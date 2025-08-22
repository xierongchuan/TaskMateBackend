<?php

declare(strict_types=1);

namespace App\Bot\Callbacks;

use App\Models\ExpenseRequest;
use App\Models\ExpenseApproval;
use App\Models\AuditLog;
use App\Models\User;
use App\Enums\ExpenseStatus;
use App\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

class ExpenseIssuedCallback
{
    /**
     * Callback pattern: expense:issued:{id}
     */
    public function __invoke(Nutgram $bot, string $id): void
    {
        try {
            // Быстро отвечаем на callback, чтобы у клиента ушёл часик
            $bot->answerCallbackQuery();

            // Кто нажал — бухгалтер (accountant)
            $accountant = auth()->user();

            if (!$accountant) {
                $bot->answerCallbackQuery(
                    text: 'Не удалось определить пользователя. Войдите в систему.',
                    show_alert: true
                );
                return;
            }

            // Заблокируем строку и выполним все изменения в транзакции
            DB::transaction(function () use ($id, $accountant, $bot) {
                // lock for update
                $req = ExpenseRequest::where('id', $id)->lockForUpdate()->firstOrFail();

                if ($req->status !== ExpenseStatus::APPROVED->value) {
                    throw new \RuntimeException('Заявка не в статусе, пригодном для выдачи.');
                }

                $comment = 'Выдано бухгалтерией';

                // Запись в approvals
                ExpenseApproval::create([
                    'expense_request_id' => $req->id,
                    'actor_id'           => $accountant->id,
                    'actor_role'         => Role::ACCOUNTANT->value,
                    'action'             => ExpenseStatus::ISSUED->value,
                    'comment'            => $comment,
                    'created_at'         => now(),
                ]);

                // Обновляем заявку
                $req->update([
                    'status'        => ExpenseStatus::ISSUED->value,
                    'accountant_id' => $accountant->id,
                    'accountant_comment' => $comment,
                    'issued_at'     => now(),
                    'updated_at'    => now(),
                ]);

                // Аудит
                AuditLog::create([
                    'table_name' => 'expense_requests',
                    'record_id'  => $req->id,
                    'actor_id'   => $accountant->id,
                    'action'     => ExpenseStatus::ISSUED->value,
                    'payload'    => [
                        'amount'  => $req->amount,
                        'currency' => $req->currency,
                        'comment' => $comment,
                        'old_status' => ExpenseStatus::APPROVED->value,
                        'new_status' => ExpenseStatus::ISSUED->value,
                    ],
                    'created_at' => now(),
                ]);
            });

            // После коммита — подтянем свежие данные для уведомлений
            $req = ExpenseRequest::find($id);
            $requester = User::find($req->requester_id);

            // Обновляем сообщение в чате бухгалтера (удаляем inline кнопки и показываем статус)
            try {
                $bot->editMessageText(
                    text: sprintf(
                        "💵 Заявка #%d — сумма %s %s\nСтатус: выдано\nПользователь: %s (ID: %d)",
                        $req->id,
                        number_format((float)$req->amount, 2, '.', ' '),
                        $req->currency,
                        $requester->full_name ?? ($requester->login ?? 'Unknown'),
                        $req->requester_id
                    ),
                    reply_markup: null
                );
            } catch (\Throwable $e) {
                Log::warning('ExpenseIssuedCallback: editMessageText failed', [
                    'request_id' => $id,
                    'message' => $e->getMessage(),
                ]);
            }

            // Уведомляем заявителя
            if ($requester && $requester->telegram_id) {
                try {
                    $bot->sendMessage(
                        chat_id: $requester->telegram_id,
                        text: sprintf(
                            "💵 Ваша заявка #%d на сумму %s %s была выдана.",
                            $req->id,
                            number_format((float)$req->amount, 2, '.', ' '),
                            $req->currency
                        )
                    );
                } catch (\Throwable $e) {
                    Log::error('ExpenseIssuedCallback: failed to notify requester', [
                        'request_id' => $id,
                        'requester_id' => $requester->id ?? null,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            Log::info("ExpenseIssuedCallback: заявка #{$id} выдана бухгалтером {$accountant->id}");
        } catch (\Throwable $e) {
            Log::error("ExpenseIssuedCallback: ошибка при выдаче заявки #{$id}", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Если уже можно ответить callback — сообщим бухгалтеру
            try {
                $bot->answerCallbackQuery(text: "Ошибка при выдаче: {$e->getMessage()}", show_alert: true);
            } catch (\Throwable $ignored) {
                // ничего
            }
        }
    }
}
