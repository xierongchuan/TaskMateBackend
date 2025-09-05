<?php

declare(strict_types=1);

namespace App\Bot\Callbacks;

use App\Bot\Abstracts\BaseCallbackHandler;
use App\Services\Contracts\ExpenseApprovalServiceInterface;
use App\Services\Contracts\NotificationServiceInterface;
use SergiX44\Nutgram\Nutgram;

/**
 * Callback handler for accountants to issue full approved amount.
 * Follows SOLID principles and uses dependency injection.
 */
class ExpenseIssuedFullCallback extends BaseCallbackHandler
{
    /**
     * Execute the full amount issuance logic.
     */
    protected function execute(Nutgram $bot, string $id): void
    {
        $bot->answerCallbackQuery();

        $accountant = $this->validateUser($bot);
        $requestId = (int) $id;

        $result = $this->getApprovalService()->issueExpense(
            $bot,
            $requestId,
            $accountant
        );

        if (!$result['success']) {
            throw new \RuntimeException($result['message'] ?? 'Ошибка при выдаче заявки');
        }

        $request = $result['request'];
        $requester = $request->requester;

        // Update the message to show issuance
        $message = sprintf(
            <<<MSG
✅ Заявка #%d выдана бухгалтером
Пользователь: %s (ID: %d)
Выданная сумма: %s %s
Полная сумма выдана
MSG,
            $request->id,
            $requester->full_name ?? ($requester->login ?? 'Unknown'),
            $request->requester_id,
            number_format((float) $request->amount, 2, '.', ' '),
            $request->currency
        );

        $this->getNotificationService()->updateMessage($bot, $message);
    }

    /**
     * Get specific error message for this callback.
     */
    protected function getErrorMessage(\Throwable $e): string
    {
        return 'Ошибка при выдаче полной суммы.';
    }

    /**
     * Get approval service instance.
     */
    private function getApprovalService(): ExpenseApprovalServiceInterface
    {
        return app(ExpenseApprovalServiceInterface::class);
    }

    /**
     * Get notification service instance.
     */
    private function getNotificationService(): NotificationServiceInterface
    {
        return app(NotificationServiceInterface::class);
    }
}
