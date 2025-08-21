<?php

declare(strict_types=1);

namespace App\Bot\Callbacks;

use App\Enums\Role;
use App\Models\ExpenseRequest;
use App\Models\ExpenseApproval;
use App\Models\AuditLog;
use App\Models\User;
use App\Enums\ExpenseStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

class ExpenseDeclineCallback
{
    /**
     * Callback data pattern: expense:cancel:{id}
     */
    public function __invoke(Nutgram $bot, string $id): void
    {
        try {
            // кто нажал — директор
            $director = auth()->user();

            $req = ExpenseRequest::find($id);
            $requester = User::find($req->requester_id);

            if (! $director) {
                $bot->answerCallbackQuery(
                    text: 'Не удалось определить пользователя. Войдите в систему.',
                    show_alert: true
                );
                return;
            }

            // Выполняем все изменения в транзакции и с блокировкой строки
            DB::transaction(function () use ($req, $director) {
                // Проверяем ожидаемый статус — используем ваш enum
                if ($req->status !== ExpenseStatus::PENDING->value) {
                    throw new \RuntimeException('Неверный статус заявки для отклонения');
                }

                // Комментарий — пока дефолтный; при необходимости можно расширить поток с вводом причины
                $comment = 'Отклонено директором';

                // Вставляем лог одобрения/отклонения
                ExpenseApproval::create([
                    'expense_request_id' => $req->id,
                    'actor_id'           => $director->id,
                    'actor_role'         => Role::DIRECTOR->value,
                    'action'             => ExpenseStatus::DECLINED->value,
                    'comment'            => $comment,
                    'created_at'         => now(),
                ]);

                // Обновляем саму заявку
                $req->update([
                    'status'              => ExpenseStatus::DECLINED->value,
                    'director_id'         => $director->id,
                    'director_comment'    => $comment,
                    // 'approved_at'         => now(),
                    'updated_at'          => now(),
                ]);

                // Пишем аудит
                AuditLog::create([
                    'table_name' => 'expense_requests',
                    'record_id'  => $req->id,
                    'actor_id'   => $director->id,
                    'action'     => ExpenseStatus::DECLINED->value,
                    'payload'    => [
                        'reason'     => $comment,
                        'old_status' => ExpenseStatus::PENDING->value,
                        'new_status' => ExpenseStatus::DECLINED->value,
                    ],
                    'created_at' => now(),
                ]);
            });

            // Редактируем сообщение у директора (убираем кнопки и показываем результат)
            $bot->editMessageText(
                text: sprintf(
                    "❌ Заявка #%d отклонена директором\nПользователь: %s (ID: %d)\nСумма: %s %s\nКомментарий: %s",
                    $req->id,
                    $requester->full_name ?? ($requester->login ?? 'Unknown'),
                    $req->requester_id,
                    number_format((float)$req->amount, 2, '.', ' '),
                    $req->currency,
                    $req->description ?: '-'
                ),
                reply_markup: null
            );

            // Уведомляем заявителя (если есть telegram_id)
            if ($requester && $requester->telegram_id) {
                try {
                    $bot->sendMessage(
                        chat_id: $requester->telegram_id,
                        text: sprintf(
                            "🚫 Ваша заявка #%d на сумму %s %s для: %s. \nБыла отклонена директором.",
                            $req->id,
                            number_format((float)$req->amount, 2, '.', ' '),
                            $req->currency,
                            $req->description ?: '-'
                        )
                    );
                } catch (\Throwable $sendEx) {
                    Log::error('Failed to send decline notification to requester', [
                        'request_id' => $req->id,
                        'requester_id' => $requester->id ?? null,
                        'message' => $sendEx->getMessage(),
                        'trace' => $sendEx->getTraceAsString(),
                    ]);
                }
            }

            Log::info("Заявка #{$id} отклонена директором {$director->id}");
        } catch (\Throwable $e) {
            Log::error("Ошибка при отклонении заявки #{$id}", [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Вежливо сообщаем директору об ошибке
            $bot->answerCallbackQuery(text: "Ошибка при отклонении заявки: {$e->getMessage()}", show_alert: true);
        }
    }
}
