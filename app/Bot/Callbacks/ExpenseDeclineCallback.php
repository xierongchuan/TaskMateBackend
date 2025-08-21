<?php

declare(strict_types=1);

namespace App\Bot\Handlers;

use App\Models\ExpenseRequest;
use App\Enums\ExpenseStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

class ExpenseDeclineCallback
{
    public function __invoke(Nutgram $bot, string $id): void
    {
        try {
            $director = auth()->user();
            $req = ExpenseRequest::where('id', $id)->lockForUpdate()->firstOrFail();
            $requester = User::find($req->requester_id);

            DB::transaction(function () use ($req, $director) {
                if ($req->status !== ExpenseStatus::PENDING->value) {
                    throw new \Exception('Неверный статус заявки');
                }

                \App\Models\ExpenseApproval::create([
                    'expense_request_id' => $req->id,
                    'actor_id' => $director->id,
                    'actor_role' => 'director',
                    'action' => ExpenseStatus::DECLINED->value,
                    'comment' => 'Нецелесообразно'
                ]);

                $req->update([
                    'status' => ExpenseStatus::DECLINED->value,
                    'director_id' => $director->id,
                    'director_comment' => 'Нецелесообразно',
                    'approved_at' => now(),
                    'updated_at' => now()
                ]);

                \App\Models\AuditLog::create([
                    'table_name' => 'expense_requests',
                    'record_id' => $req->id,
                    'actor_id' => $director->id,
                    'action' => ExpenseStatus::DECLINED->value,
                    'payload' => json_encode(['reason' => 'Нецелесообразно'])
                ]);
            });

            $bot->editMessageText(
                text: sprintf(
                    <<<MSG
❌ Заявка #%d отклонена директором
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
                text: "🚫 Ваша заявка #{$req->id} отклонена директором. Причина: Нецелесообразно."
            );

            Log::info("Заявка #{$req->id} отклонена директором {$director->id}");
        } catch (\Throwable $e) {
            Log::error("Ошибка при отклонении заявки #$id", [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            $bot->answerCallbackQuery(text: "Ошибка при отклонении заявки.", show_alert: true);
        }
    }
}
