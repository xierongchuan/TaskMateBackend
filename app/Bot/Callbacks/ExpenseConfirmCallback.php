<?php

declare(strict_types=1);

namespace App\Bot\Callbacks;

use App\Bot\Abstracts\BaseCallbackHandler;
use App\Services\Contracts\ExpenseApprovalServiceInterface;
use App\Services\Contracts\NotificationServiceInterface;
use SergiX44\Nutgram\Nutgram;

/**
 * Handle expense confirmation callback.
 * Refactored to use base class and follow SOLID principles.
 */

class ExpenseConfirmCallback extends BaseCallbackHandler
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
     * Execute the approval logic.
     */
    protected function execute(Nutgram $bot, string $id): void
    {
        $director = $this->validateUser($bot);
        $requestId = (int) $id;

        $result = $this->getApprovalService()->approveRequest(
            $bot,
            $requestId,
            $director
        );

        if (!$result['success']) {
            throw new \RuntimeException($result['message'] ?? 'Ошибка при подтверждении заявки');
        }

        $request = $result['request'];
        $requester = $request->requester;

        // Update the message to show approval
        $message = sprintf(
            <<<MSG
✅ Заявка #%d подтверждена директором
Пользователь: %s (ID: %d)
Сумма: %s %s
Комментарий: %s
MSG,
            $request->id,
            $requester->full_name ?? ($requester->login ?? 'Unknown'),
            $request->requester_id,
            number_format((float) $request->amount, 2, '.', ' '),
            $request->currency,
            $request->description ?: '-'
        );

        $bot->editMessageText(
            text: $message,
            reply_markup: null
        );
    }

    /**
     * Get specific error message for this callback.
     */
    protected function getErrorMessage(\Throwable $e): string
    {
        return 'Ошибка при подтверждении заявки.';
    }
}
