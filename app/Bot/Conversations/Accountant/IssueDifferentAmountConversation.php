<?php

declare(strict_types=1);

namespace App\Bot\Conversations\Accountant;

use App\Bot\Abstracts\BaseConversationHandler;
use App\Enums\ExpenseStatus;
use App\Enums\Role;
use App\Models\ExpenseRequest;
use App\Models\ExpenseApproval;
use App\Models\AuditLog;
use App\Services\Contracts\ExpenseApprovalServiceInterface;
use App\Services\Contracts\NotificationServiceInterface;
use App\Services\Contracts\ValidationServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

/**
 * Conversation for accountants to issue a different amount than approved.
 * Follows SOLID principles and uses service locator pattern.
 */
class IssueDifferentAmountConversation extends BaseConversationHandler
{
    public int $requestId;
    public float $newAmount;
    public ExpenseRequest $originalRequest;

    /**
     * Start the conversation by asking for the new amount.
     */
    public function start(Nutgram $bot): void
    {
        try {
            // Extract request ID from callback data
            $callbackData = $bot->callbackQuery()?->data ?? '';
            $this->requestId = (int) str_replace('expense:issued_different:', '', $callbackData);

            // Load original request to show context
            $this->originalRequest = ExpenseRequest::with('requester')->findOrFail($this->requestId);

            // Check if request is in correct status
            if ($this->originalRequest->status !== ExpenseStatus::APPROVED->value) {
                $bot->answerCallbackQuery();
                $bot->sendMessage('Заявка должна быть подтверждена для выдачи.');
                $this->end();
                return;
            }

            $bot->answerCallbackQuery();
            $message = sprintf(
                "Заявка #%d\nПользователь: %s\nПодтвержденная сумма: %s %s\n\n" .
                "Введите сумму для выдачи (или /cancel для отмены):",
                $this->originalRequest->id,
                $this->originalRequest->requester->full_name ?? $this->originalRequest->requester->login ?? 'Unknown',
                number_format((float) $this->originalRequest->amount, 2, '.', ' '),
                $this->originalRequest->currency
            );

            $bot->sendMessage($message);
            $this->next('handleAmount');
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'start');
        }
    }

    /**
     * Handle amount input and issue expense with new amount.
     */
    public function handleAmount(Nutgram $bot): void
    {
        try {
            $text = trim($bot->message()?->text ?? '');

            // Check for cancel command
            if (strtolower($text) === '/cancel') {
                $bot->sendMessage('Операция отменена.');
                $this->end();
                return;
            }

            $validation = $this->getValidationService()->validateAmount($text);
            if (!$validation['valid']) {
                $bot->sendMessage($validation['message']);
                $this->next('handleAmount');
                return;
            }

            $this->newAmount = $validation['value'];
            $accountant = $this->getAuthenticatedUser();

            // Issue expense with different amount using custom logic
            $result = $this->issueExpenseWithDifferentAmount(
                $bot,
                $this->requestId,
                $accountant,
                $this->newAmount
            );

            if ($result['success']) {
                $message = sprintf(
                    "✅ Заявка #%d выдана с измененной суммой\nПодтвержденная сумма: %s %s\nВыданная сумма: %s %s",
                    $this->requestId,
                    number_format((float) $this->originalRequest->amount, 2, '.', ' '),
                    $this->originalRequest->currency,
                    number_format($this->newAmount, 2, '.', ' '),
                    $this->originalRequest->currency
                );
                $bot->sendMessage($message);
            } else {
                $bot->sendMessage('Ошибка: ' . ($result['message'] ?? 'Не удалось выдать заявку'));
            }

            $this->end();
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handleAmount');
        }
    }

    /**
     * Custom logic to issue expense with different amount.
     */
    private function issueExpenseWithDifferentAmount(Nutgram $bot, int $requestId, $accountant, float $newAmount): array
    {
        try {
            DB::transaction(function () use ($requestId, $accountant, $newAmount) {
                $request = ExpenseRequest::where('id', $requestId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($request->status !== ExpenseStatus::APPROVED->value) {
                    throw new \RuntimeException('Неверный статус заявки для выдачи');
                }

                $originalAmount = $request->amount;

                // Update request with new issued amount and add comment about the difference
                $comment = "Выдано бухгалтером. Подтвержденная сумма: {$originalAmount} {$request->currency}, " .
                    "выдана: {$newAmount} {$request->currency}";

                // Create approval record with different amount info
                ExpenseApproval::create([
                    'expense_request_id' => $request->id,
                    'actor_id' => $accountant->id,
                    'actor_role' => Role::ACCOUNTANT->value,
                    'action' => ExpenseStatus::ISSUED->value,
                    'comment' => $comment,
                    'created_at' => now(),
                ]);

                // Update request - keep original amount in amount field for audit
                $request->update([
                    'status' => ExpenseStatus::ISSUED->value,
                    'accountant_id' => $accountant->id,
                    'issued_at' => now(),
                    'updated_at' => now(),
                ]);

                // Create audit log with amount change info
                AuditLog::create([
                    'table_name' => 'expense_requests',
                    'record_id' => $request->id,
                    'actor_id' => $accountant->id,
                    'action' => ExpenseStatus::ISSUED->value,
                    'payload' => [
                        'original_amount' => $originalAmount,
                        'issued_amount' => $newAmount,
                        'currency' => $request->currency,
                        'old_status' => ExpenseStatus::APPROVED->value,
                        'new_status' => ExpenseStatus::ISSUED->value,
                    ],
                    'created_at' => now(),
                ]);
            });

            // Send notification to requester about the issued amount
            $request = ExpenseRequest::with('requester')->findOrFail($requestId);
            if ($request->requester) {
                $this->getNotificationService()->notifyExpenseStatus(
                    $bot,
                    $request->requester,
                    $request,
                    ExpenseStatus::ISSUED->value,
                    "Выдана сумма: " . number_format($newAmount, 2, '.', ' ') . " " . $request->currency
                );
            }

            Log::info("Заявка #{$requestId} выдана бухгалтером {$accountant->id} с измененной суммой: {$newAmount}");

            return [
                'success' => true,
                'request' => $request
            ];
        } catch (\Throwable $e) {
            Log::error("Ошибка при выдаче заявки #{$requestId} с измененной суммой", [
                'accountant_id' => $accountant->id,
                'new_amount' => $newAmount,
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
     * Get validation service instance.
     */
    private function getValidationService(): ValidationServiceInterface
    {
        return app(ValidationServiceInterface::class);
    }

    /**
     * Get notification service instance.
     */
    private function getNotificationService(): NotificationServiceInterface
    {
        return app(NotificationServiceInterface::class);
    }
}
