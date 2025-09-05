<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExpenseStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\ExpenseApproval;
use App\Models\ExpenseRequest;
use App\Models\User;
use App\Services\Contracts\ExpenseApprovalServiceInterface;
use App\Services\Contracts\NotificationServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * Service for handling expense approval operations.
 * Follows Single Responsibility Principle and encapsulates approval logic.
 */
class ExpenseApprovalService implements ExpenseApprovalServiceInterface
{
    public function __construct(
        private NotificationServiceInterface $notificationService
    ) {
    }

    /**
     * Approve expense request.
     */
    public function approveRequest(
        Nutgram $bot,
        int $requestId,
        User $director,
        ?string $comment = null
    ): array {
        try {
            DB::transaction(function () use ($requestId, $director, $comment) {
                $request = ExpenseRequest::where('id', $requestId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($request->status !== ExpenseStatus::PENDING->value) {
                    throw new \RuntimeException('Неверный статус заявки для подтверждения');
                }

                // Create approval record
                ExpenseApproval::create([
                    'expense_request_id' => $request->id,
                    'actor_id' => $director->id,
                    'actor_role' => Role::DIRECTOR->value,
                    'action' => ExpenseStatus::APPROVED->value,
                    'comment' => $comment ?? '-',
                    'created_at' => now(),
                ]);

                // Update request
                $request->update([
                    'status' => ExpenseStatus::APPROVED->value,
                    'director_id' => $director->id,
                    'director_comment' => $comment ?? '-',
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);

                // Create audit log
                $this->createAuditLog($request, $director, ExpenseStatus::APPROVED->value, [
                    'comment' => $comment,
                    'old_status' => ExpenseStatus::PENDING->value,
                    'new_status' => ExpenseStatus::APPROVED->value,
                ]);
            });

            // Send notifications after successful transaction
            $request = ExpenseRequest::with('requester')->findOrFail($requestId);
            $this->sendApprovalNotifications($bot, $request, $comment);

            Log::info("Заявка #{$requestId} подтверждена директором {$director->id}");

            return [
                'success' => true,
                'request' => $request
            ];
        } catch (Throwable $e) {
            Log::error("Ошибка при подтверждении заявки #{$requestId}", [
                'director_id' => $director->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Decline expense request.
     */
    public function declineRequest(
        Nutgram $bot,
        int $requestId,
        User $director,
        ?string $reason = null
    ): array {
        try {
            DB::transaction(function () use ($requestId, $director, $reason) {
                $request = ExpenseRequest::where('id', $requestId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($request->status !== ExpenseStatus::PENDING->value) {
                    throw new \RuntimeException('Неверный статус заявки для отклонения');
                }

                $comment = $reason ?? 'Отклонено директором';

                // Create approval record
                ExpenseApproval::create([
                    'expense_request_id' => $request->id,
                    'actor_id' => $director->id,
                    'actor_role' => Role::DIRECTOR->value,
                    'action' => ExpenseStatus::DECLINED->value,
                    'comment' => $comment,
                    'created_at' => now(),
                ]);

                // Update request
                $request->update([
                    'status' => ExpenseStatus::DECLINED->value,
                    'director_id' => $director->id,
                    'director_comment' => $comment,
                    'updated_at' => now(),
                ]);

                // Create audit log
                $this->createAuditLog($request, $director, ExpenseStatus::DECLINED->value, [
                    'reason' => $comment,
                    'old_status' => ExpenseStatus::PENDING->value,
                    'new_status' => ExpenseStatus::DECLINED->value,
                ]);
            });

            // Send notifications after successful transaction
            $request = ExpenseRequest::with('requester')->findOrFail($requestId);
            $this->sendDeclineNotifications($bot, $request, $reason);

            Log::info("Заявка #{$requestId} отклонена директором {$director->id}");

            return [
                'success' => true,
                'request' => $request
            ];
        } catch (Throwable $e) {
            Log::error("Ошибка при отклонении заявки #{$requestId}", [
                'director_id' => $director->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Mark expense as issued.
     */
    public function issueExpense(
        Nutgram $bot,
        int $requestId,
        User $accountant
    ): array {
        try {
            DB::transaction(function () use ($requestId, $accountant) {
                $request = ExpenseRequest::where('id', $requestId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($request->status !== ExpenseStatus::APPROVED->value) {
                    throw new \RuntimeException('Неверный статус заявки для выдачи');
                }

                // Create approval record
                ExpenseApproval::create([
                    'expense_request_id' => $request->id,
                    'actor_id' => $accountant->id,
                    'actor_role' => Role::ACCOUNTANT->value,
                    'action' => ExpenseStatus::ISSUED->value,
                    'comment' => 'Выдано бухгалтером',
                    'created_at' => now(),
                ]);

                // Update request
                $request->update([
                    'status' => ExpenseStatus::ISSUED->value,
                    'accountant_id' => $accountant->id,
                    'issued_at' => now(),
                    'updated_at' => now(),
                ]);

                // Create audit log
                $this->createAuditLog($request, $accountant, ExpenseStatus::ISSUED->value, [
                    'old_status' => ExpenseStatus::APPROVED->value,
                    'new_status' => ExpenseStatus::ISSUED->value,
                ]);
            });

            // Send notifications after successful transaction
            $request = ExpenseRequest::with('requester')->findOrFail($requestId);
            $this->sendIssuedNotifications($bot, $request);

            Log::info("Заявка #{$requestId} выдана бухгалтером {$accountant->id}");

            return [
                'success' => true,
                'request' => $request
            ];
        } catch (Throwable $e) {
            Log::error("Ошибка при выдаче заявки #{$requestId}", [
                'accountant_id' => $accountant->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Send approval notifications.
     */
    private function sendApprovalNotifications(Nutgram $bot, ExpenseRequest $request, ?string $comment): void
    {
        // Notify requester (without director's comment)
        if ($request->requester) {
            $this->notificationService->notifyExpenseStatus(
                $bot,
                $request->requester,
                $request,
                ExpenseStatus::APPROVED->value,
                null // Don't pass comment to user
            );
        }

        // Notify accountant (with director's comment)
        $accountant = User::where('company_id', $request->company_id)
            ->where('role', Role::ACCOUNTANT->value)
            ->first();

        if ($accountant) {
            $this->notificationService->notifyAccountantApproved($bot, $accountant, $request, $comment);
        }
    }

    /**
     * Send decline notifications.
     */
    private function sendDeclineNotifications(Nutgram $bot, ExpenseRequest $request, ?string $reason): void
    {
        if ($request->requester) {
            $this->notificationService->notifyExpenseStatus(
                $bot,
                $request->requester,
                $request,
                ExpenseStatus::DECLINED->value,
                $reason
            );
        }
    }

    /**
     * Send issued notifications.
     */
    private function sendIssuedNotifications(Nutgram $bot, ExpenseRequest $request): void
    {
        if ($request->requester) {
            $this->notificationService->notifyExpenseStatus(
                $bot,
                $request->requester,
                $request,
                ExpenseStatus::ISSUED->value
            );
        }
    }

    /**
     * Create audit log entry.
     */
    private function createAuditLog(ExpenseRequest $request, User $actor, string $action, array $payload): void
    {
        AuditLog::create([
            'table_name' => 'expense_requests',
            'record_id' => $request->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
