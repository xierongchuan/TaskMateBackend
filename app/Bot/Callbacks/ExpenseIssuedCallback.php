<?php

declare(strict_types=1);

namespace App\Bot\Callbacks;

use App\Bot\Abstracts\BaseCallbackHandler;
use App\Services\Contracts\ExpenseApprovalServiceInterface;
use App\Services\Contracts\NotificationServiceInterface;
use SergiX44\Nutgram\Nutgram;

/**
 * Handle expense issued callback.
 * Refactored to use base class and follow SOLID principles.
 */

class ExpenseIssuedCallback extends BaseCallbackHandler
{
    /**
     * Get approval service from container.
     */
    private function getApprovalService(): ExpenseApprovalServiceInterface
    {
        return app(ExpenseApprovalServiceInterface::class);
    }

    /**
     * Get notification service from container.
     */
    private function getNotificationService(): NotificationServiceInterface
    {
        return app(NotificationServiceInterface::class);
    }

    /**
     * Execute the issued logic.
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

        // Update the message to show issued status
        $message = sprintf(
            "💵 Заявка #%d — сумма %s %s\nСтатус: выдано\nПользователь: %s (ID: %d)",
            $request->id,
            number_format((float)$request->amount, 2, '.', ' '),
            $request->currency,
            $requester->full_name ?? ($requester->login ?? 'Unknown'),
            $request->requester_id
        );

        $this->getNotificationService()->updateMessage($bot, $message);
    }

    /**
     * Get specific error message for this callback.
     */
    protected function getErrorMessage(\Throwable $e): string
    {
        return 'Ошибка при выдаче заявки.';
    }
}
