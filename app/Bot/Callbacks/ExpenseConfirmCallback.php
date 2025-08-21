<?php

declare(strict_types=1);

namespace App\Bot\Handlers;

use App\Models\ExpenseRequest;
use App\Enums\ExpenseStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

class ExpenseConfirmCallback
{
    public function __invoke(Nutgram $bot, string $id): void
    {
        try {
            $user = auth()->user();
            $req = ExpenseRequest::where('id', $id)->lockForUpdate()->firstOrFail();
            $requester = User::find($req->requester_id);

            DB::transaction(function () use ($req) {
                if ($req->status !== ExpenseStatus::PENDING->value) {
                    throw new \Exception('Неверный статус заявки');
                }

                \App\Models\ExpenseApproval::create([
                    'expense_request_id' => $req->id,
                    'actor_id' => $req->requester_id,
                    'actor_role' => 'director',
                    'action' => ExpenseStatus::APPROVED->value,
                    'comment' => 'OK'
                ]);

                $req->update([
                    'status' => ExpenseStatus::APPROVED->value,
                    'director_id' => $req->requester_id,
                    'director_comment' => 'OK',
                    'approved_at' => now(),
                ]);

                \App\Models\AuditLog::create([
                    'table_name' => 'expense_requests',
                    'record_id' => $req->id,
                    'actor_id' => $req->requester_id,
                    'action' => ExpenseStatus::APPROVED->value,
                    'payload' => [
                        'old_status' => ExpenseStatus::PENDING->value,
                        'new_status' => ExpenseStatus::APPROVED->value
                    ]
                ]);
            });

            $bot->editMessageText(
                text: sprintf(
                    <<<MSG
✅ Заявка #%d подтверждена директором
Пользователь: %s (ID: %d)
Сумма: %s %s
Комментарий: %s
MSG,
                    $req->id,
                    $requester->full_name ?? ($requester->login ?? 'Unknown'),
                    $req->requester_id,
                    number_format((float) $req->amount, 2, '.', ' '),
                    $req->currency,
                    $req->description ?: '-'
                ),
                reply_markup: null
            );

            $bot->sendMessage(
                chat_id: $requester->telegram_id,
                text: "🎉 Ваша заявка #{$req->id} подтверждена директором."
            );

            Log::info("Заявка #{$req->id} подтверждена директором {$user->id}");
        } catch (\Throwable $e) {
            Log::error("Ошибка при подтверждении заявки #$id", [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            $bot->answerCallbackQuery(text: "Ошибка при подтверждении заявки.", show_alert: true);
        }

        // // cancel
        // $bot->onCallbackQueryData('expense:cancel:{id}', function (Nutgram $bot, string $id) {
        //     try {
        //         $req = ExpenseRequest::find($id);

        //         if (!$req) {
        //             $bot->answerCallbackQuery(text: "Заявка не найдена.", show_alert: true);
        //             Log::warning("Попытка отменить несуществующую заявку #$id директором {$bot->userId()}");
        //             return;
        //         }

        //         $req->update(['status' => ExpenseStatus::DECLINED->value]);

        //         $bot->editMessageText(
        //             text: "❌ Заявка #{$req->id} отклонена.",
        //             reply_markup: null
        //         );

        //         $bot->sendMessage(
        //             chat_id: $req->requester->telegram_id,
        //             text: "🚫 Ваша заявка #{$req->id} была отклонена директором."
        //         );

        //         Log::info("Заявка #{$req->id} отклонена директором {$bot->userId()}");
        //     } catch (\Throwable $e) {
        //         Log::error("Ошибка при отклонении заявки #$id", [
        //             'exception' => $e,
        //             'trace' => $e->getTraceAsString(),
        //         ]);
        //         $bot->answerCallbackQuery(text: "Ошибка при отклонении заявки.", show_alert: true);
        //     }
        // });
    }
}
