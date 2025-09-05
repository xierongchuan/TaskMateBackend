<?php

declare(strict_types=1);

namespace App\Bot\Conversations\Director;

use App\Bot\Abstracts\BaseConversation;
use App\Services\Contracts\ExpenseApprovalServiceInterface;
use App\Services\Contracts\ValidationServiceInterface;
use SergiX44\Nutgram\Nutgram;

/**
 * Conversation for confirming expenses with comment.
 * Refactored to use base class and follow SOLID principles.
 */

class ConfirmWithCommentConversation extends BaseConversation
{
    protected ?string $step = 'askComment';

    protected int $requestId;
    protected int $requestMessageId;
    protected string $comment = '';

    /**
     * Get validation service from container.
     */
    private function getValidationService(): ValidationServiceInterface
    {
        return app(ValidationServiceInterface::class);
    }

    /**
     * Get approval service from container.
     */
    private function getApprovalService(): ExpenseApprovalServiceInterface
    {
        return app(ExpenseApprovalServiceInterface::class);
    }

    /**
     * Ask for comment to approve with.
     */
    public function askComment(Nutgram $bot, int|string $id): void
    {
        try {
            $this->requestId = (int) $id;
            $this->requestMessageId = $bot->messageId();

            $bot->answerCallbackQuery();
            $bot->sendMessage('Введите комментарий для подтверждения заявки (или /cancel для отмены):');
            $this->next('handleComment');
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'askComment');
        }
    }

    /**
     * Handle comment input and approve expense.
     */
    public function handleComment(Nutgram $bot): void
    {
        try {
            $text = trim($bot->message()?->text ?? '');

            // Check for cancel command
            if (strtolower($text) === '/cancel') {
                $bot->sendMessage('Операция отменена.');
                $this->end();
                return;
            }

            $validation = $this->getValidationService()->validateNotEmpty($text);
            if (!$validation['valid']) {
                $bot->sendMessage('Комментарий не может быть пустым. Пожалуйста, введите ещё раз:');
                $this->next('handleComment');
                return;
            }

            $this->comment = $text;
            $director = $this->getAuthenticatedUser();

            $result = $this->getApprovalService()->approveRequest(
                $bot,
                $this->requestId,
                $director,
                $this->comment
            );

            if (!$result['success']) {
                throw new \RuntimeException($result['message'] ?? 'Ошибка при подтверждении');
            }

            $request = $result['request'];
            $requester = $request->requester;

            // Update the original message
            try {
                $bot->editMessageText(
                    text: sprintf(
                        "✅ Заявка #%d подтверждена директором\n" .
                        "Пользователь: %s (ID: %d)\n" .
                        "Сумма: %s %s\n" .
                        "Комментарий: %s",
                        $request->id,
                        $requester->full_name ?? ($requester->login ?? 'Unknown'),
                        $request->requester_id,
                        number_format((float)$request->amount, 2, '.', ' '),
                        $request->currency,
                        $this->comment
                    ),
                    reply_markup: null,
                    message_id: $this->requestMessageId
                );
            } catch (\Throwable $e) {
                // Log but don't fail the whole operation
                \Illuminate\Support\Facades\Log::warning('Failed to edit message', [
                    'message' => $e->getMessage(),
                    'request_id' => $this->requestId
                ]);
            }

            $bot->sendMessage('✅ Заявка успешно подтверждена с комментарием.');
            $this->end();
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handleComment');
        }
    }
}
