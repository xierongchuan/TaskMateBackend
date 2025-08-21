<?php

declare(strict_types=1);

namespace App\Bot\Conversations\User;

use App\Enums\ExpenseStatus;
use App\Services\ExpenseService;
use App\Traits\KeyboardTrait;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use Illuminate\Support\Facades\DB;
use App\Models\ExpenseRequest;
use App\Models\ExpenseApproval;
use App\Models\User;

class RequestExpenseConversation extends Conversation
{
    protected ?string $step = 'askAmount';

    public ?float $amount = null;
    public ?string $comment = null;

    public function askAmount(Nutgram $bot)
    {
        $bot->sendMessage('Введите сумму в UZS:');

        $this->next('handleAmount');
    }

    public function handleAmount(Nutgram $bot)
    {
        $text = trim($bot->message()?->text ?? '');
        // допускаем запятую как разделитель, убираем пробелы
        $normalized = str_replace([',', ' '], ['.', ''], $text);

        if ($normalized === '' || !is_numeric($normalized) || (float)$normalized <= 0) {
            $bot->sendMessage('Неверный формат суммы. Введите положительное число, например: 100000');
            // остаёмся в этом же шаге — повторный ввод
            $this->next('handleAmount');
            return;
        }

        $this->amount = (float) $normalized;
        $bot->sendMessage("Сумма принята: {$this->amount}\nПожалуйста, введите комментарий (цель расхода):");
        $this->next('handleComment');
    }

    public function handleComment(Nutgram $bot)
    {
        $this->comment = trim($bot->message()?->text ?? '');

        if ($this->comment === '') {
            $bot->sendMessage('Комментарий не может быть пустым. Введите, пожалуйста, комментарий:');
            $this->next('handleComment');
            return;
        }

        $user = auth()->user();

        $result = ExpenseService::createRequest(
            $bot,
            $user->id,
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
                reply_markup: KeyboardTrait::userMenu()
            );
            $this->end();
        }

        $bot->sendMessage(
            "Готово — заявка на сумму {$this->amount} UZS создана. Спасибо!",
            reply_markup: KeyboardTrait::userMenu()
        );
        $this->end();
    }

    // опционально: вызывается при завершении (end) — можно уведомить, почистить данные и т.д.
    public function closing(Nutgram $bot)
    {
        // например, логирование или уведомление менеджера
    }
}
