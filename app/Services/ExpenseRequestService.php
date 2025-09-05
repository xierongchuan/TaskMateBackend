<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExpenseStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\ExpenseRequest;
use App\Models\User;
use App\Services\Contracts\ExpenseServiceInterface;
use App\Services\Contracts\NotificationServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * Service for expense request creation and management.
 * Follows Single Responsibility Principle - only handles expense creation.
 */
class ExpenseRequestService implements ExpenseServiceInterface
{
    public function __construct(
        private NotificationServiceInterface $notificationService
    ) {
    }

    /**
     * Create a new expense request.
     */
    public function createRequest(
        Nutgram $bot,
        User $requester,
        string $description,
        float $amount,
        string $currency = 'UZS'
    ): ?int {
        try {
            $requestId = DB::transaction(function () use ($requester, $description, $amount, $currency) {
                // Create expense request
                $request = ExpenseRequest::create([
                    'requester_id' => $requester->id,
                    'description' => $description,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => ExpenseStatus::PENDING->value,
                    'company_id' => $requester->company_id,
                ]);

                // Create audit log
                AuditLog::create([
                    'table_name' => 'expense_requests',
                    'record_id' => $request->id,
                    'actor_id' => $requester->id,
                    'action' => 'insert',
                    'payload' => [
                        'amount' => $amount,
                        'currency' => $currency,
                        'description' => $description
                    ],
                    'created_at' => now(),
                ]);

                Log::info("Заявка #{$request->id} успешно создана пользователем {$requester->id}");

                return $request->id;
            });

            // Send notification to director after successful transaction
            if ($requestId !== null) {
                $this->notifyDirector($bot, $requester, $requestId);
            }

            return $requestId;
        } catch (Throwable $e) {
            Log::error('Ошибка при создании заявки', [
                'requester_id' => $requester->id,
                'amount' => $amount,
                'currency' => $currency,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Delete expense request.
     */
    public function deleteRequest(int $requestId, int $actorId, ?string $reason = null): void
    {
        DB::transaction(function () use ($requestId, $actorId, $reason) {
            $request = ExpenseRequest::where('id', $requestId)
                ->lockForUpdate()
                ->firstOrFail();

            $request->delete(); // ON DELETE CASCADE should remove related approvals

            AuditLog::create([
                'table_name' => 'expense_requests',
                'record_id' => $requestId,
                'actor_id' => $actorId,
                'action' => 'delete',
                'payload' => ['reason' => $reason],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Notify director about new expense request.
     */
    private function notifyDirector(Nutgram $bot, User $requester, int $requestId): void
    {
        try {
            $companyId = $requester->company_id;
            if ($companyId === null) {
                Log::warning('Requester has no company_id', [
                    'requester_id' => $requester->id,
                    'request_id' => $requestId
                ]);
                return;
            }

            $director = User::where('company_id', $companyId)
                ->where('role', Role::DIRECTOR->value)
                ->whereNotNull('telegram_id')
                ->first();

            if (!$director) {
                Log::info('No director to notify', [
                    'company_id' => $companyId,
                    'request_id' => $requestId
                ]);
                return;
            }

            $request = ExpenseRequest::with('requester')->findOrFail($requestId);

            $this->notificationService->notifyDirectorNewRequest($bot, $director, $request);

            Log::info('Director notified about new request', [
                'director_id' => $director->id,
                'request_id' => $requestId,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to notify director', [
                'request_id' => $requestId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
