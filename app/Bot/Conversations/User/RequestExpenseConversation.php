<?php

declare(strict_types=1);

namespace App\Bot\Conversations\User;

use App\Bot\Abstracts\BaseConversation;
use App\Services\Contracts\ExpenseServiceInterface;
use App\Services\Contracts\ValidationServiceInterface;
use SergiX44\Nutgram\Nutgram;

/**
 * Conversation for requesting expenses.
 * Refactored to use base class and follow SOLID principles.
 */

class RequestExpenseConversation extends BaseConversation
{
    protected ?string $step = 'askAmount';

    public ?float $amount = null;
    public ?string $comment = null;

    /**
     * Get validation service from container.
     */
    private function getValidationService(): ValidationServiceInterface
    {
        return app(ValidationServiceInterface::class);
    }

    /**
     * Get expense service from container.
     */
    private function getExpenseService(): ExpenseServiceInterface
    {
        return app(ExpenseServiceInterface::class);
    }

    /**
     * Ask for expense amount.
     */
    public function askAmount(Nutgram $bot)
    {
        $bot->sendMessage('Введите сумму в UZS:');
        $this->next('handleAmount');
    }

    /**
     * Handle amount input with validation.
     */
    public function handleAmount(Nutgram $bot)
    {
        try {
            $text = trim($bot->message()?->text ?? '');
            $validation = $this->getValidationService()->validateAmount($text);

            if (!$validation['valid']) {
                $bot->sendMessage($validation['message']);
                $this->next('handleAmount');
                return;
            }

            $this->amount = $validation['value'];
            $bot->sendMessage(
                "Сумма принята: " . number_format($this->amount, 2, '.', ' ')
                . "\nПожалуйста, введите комментарий (цель расхода):"
            );
            $this->next('handleComment');
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handleAmount');
        }
    }

    /**
     * Handle comment input with validation.
     */
    public function handleComment(Nutgram $bot)
    {
        try {
            $text = trim($bot->message()?->text ?? '');
            $validation = $this->getValidationService()->validateComment($text);

            if (!$validation['valid']) {
                $bot->sendMessage($validation['message']);
                $this->next('handleComment');
                return;
            }

            $this->comment = $text;
            $user = $this->getAuthenticatedUser();

            $result = $this->getExpenseService()->createRequest(
                $bot,
                $user,
                $this->comment,
                $this->amount,
                'UZS'
            );

            if ($result === null) {
                $bot->sendMessage(
                    <<<MSG
В процессе создания заявки произошла ошибка,
просим немедленно сообщить администратору
и подождать до починки неполадки в системе!
MSG,
                    reply_markup: static::userMenu()
                );
                $this->end();
                return;
            }

            $bot->sendMessage(
                "Готово! — Создана заявка #$result\nНа сумму: " . number_format($this->amount, 2, '.', ' ')
                . " UZS",
                reply_markup: static::userMenu()
            );
            $this->end();
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handleComment');
        }
    }

    /**
     * Get default keyboard for user.
     */
    protected function getDefaultKeyboard()
    {
        return static::userMenu();
    }
}
