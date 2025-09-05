<?php

declare(strict_types=1);

namespace App\Bot\Callbacks;

use App\Bot\Abstracts\BaseCallbackHandler;
use App\Services\Contracts\ExpenseApprovalServiceInterface;
use App\Services\Contracts\NotificationServiceInterface;
use SergiX44\Nutgram\Nutgram;

/**
 * Handle expense decline callback.
 * Refactored to use base class and follow SOLID principles.
 */

class ExpenseDeclineCallback extends BaseCallbackHandler
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
     * Execute the decline logic.
     */
    protected function execute(Nutgram $bot, string $id): void
    {
        $director = $this->validateUser($bot);
        $requestId = (int) $id;

        $result = $this->getApprovalService()->declineRequest(
            $bot,
            $requestId,
            $director,
            'Отклонено директором'
        );

        if (!$result['success']) {
            throw new \RuntimeException($result['message'] ?? 'Ошибка при отклонении заявки');
        }

        $request = $result['request'];
        $requester = $request->requester;

        // Update the message to show decline
        $message = sprintf(
            "❌ Заявка #%d отклонена директором\nПользователь: %s (ID: %d)\nСумма: %s %s\nКомментарий: %s",
            $request->id,
            $requester->full_name ?? ($requester->login ?? 'Unknown'),
            $request->requester_id,
            number_format((float)$request->amount, 2, '.', ' '),
            $request->currency,
            $request->description ?: '-'
        );

        $this->getNotificationService()->updateMessage($bot, $message);
    }

    /**
     * Get specific error message for this callback.
     */
    protected function getErrorMessage(\Throwable $e): string
    {
        return 'Ошибка при отклонении заявки.';
    }
}
