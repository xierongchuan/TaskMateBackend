<?php

declare(strict_types=1);

namespace App\Conversations;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use Illuminate\Support\Facades\DB;
use App\Models\ExpenseRequest;
use App\Models\ExpenseApproval;
use App\Models\User;

class RequestExpenseConversation extends Conversation
{
    // стартовый шаг
    protected ?string $step = 'askAmount';

    // публичные свойства сериализуются автоматически — сохранятся между шагами
    public ?float $amount = null;
    public ?string $comment = null;

    public function askAmount(Nutgram $bot)
    {
        $bot->sendMessage('Введите сумму (например: 1200 или 1200.50):');
        // следующий ожидаемый метод (будет вызван при следующем сообщении пользователя)
        $this->next('handleAmount');
    }

    public function handleAmount(Nutgram $bot)
    {
        $text = trim($bot->message()?->text ?? '');
        // допускаем запятую как разделитель, убираем пробелы
        $normalized = str_replace([',', ' '], ['.', ''], $text);

        if ($normalized === '' || !is_numeric($normalized) || (float)$normalized <= 0) {
            $bot->sendMessage('Неверный формат суммы. Введите положительное число, например: 1200 или 1200.50');
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

        // --- сохраняем в БД атомарно: заявка + лог/аппрув ---
        DB::transaction(function () use ($bot) {
            // Пример: сопоставим Telegram user с локальным пользователем
            $tgUserId = $this->getUserId(); // Telegram user id
            $localUser = User::firstOrCreate(
                ['telegram_id' => $tgUserId],
                ['login' => "tg_{$tgUserId}", 'full_name' => $bot->from()?->first_name ?? null]
            );

            $req = ExpenseRequest::create([
                'requester_id'   => $localUser->id,
                'title'          => 'Заявка из бота',
                'description'    => $this->comment,
                'amount'         => $this->amount,
                'currency'       => 'UZS',
                'status'         => 'pending_manager',
            ]);

            // лог создания (expense_approvals или audit_logs по вашей схеме)
            ExpenseApproval::create([
                'expense_request_id' => $req->id,
                'actor_id'           => $localUser->id,
                'actor_role'         => 'user',
                'action'             => 'created',
                'comment'            => 'Создано через бота'
            ]);
        });

        $bot->sendMessage("Готово — заявка на сумму {$this->amount} создана. Спасибо!");
        $this->end();
    }

    // опционально: вызывается при завершении (end) — можно уведомить, почистить данные и т.д.
    public function closing(Nutgram $bot)
    {
        // например, логирование или уведомление менеджера
    }
}
