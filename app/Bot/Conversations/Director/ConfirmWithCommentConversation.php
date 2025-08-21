<?php

declare(strict_types=1);

namespace App\Bot\Conversations\Director;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\ExpenseRequest;
use App\Models\ExpenseApproval;
use App\Models\AuditLog;
use App\Models\User;
use App\Enums\ExpenseStatus;

class ConfirmWithCommentConversation extends Conversation
{
    protected ?string $step = 'askComment';

    protected int $requestId;
    protected int $requestMessageId;

    protected string $comment = '';

    public function askComment(Nutgram $bot, int|string $id): void
    {
        $this->requestId = (int) $id;

        $this->requestMessageId = $bot->messageId();

        $bot->answerCallbackQuery();

        $bot->sendMessage('Введите комментарий для подтверждения заявки (или /cancel для отмены):');
        $this->next('handleComment');
    }

    public function handleComment(Nutgram $bot): void
    {
        $text = trim($bot->message()?->text ?? '');
        if ($text === '') {
            $bot->sendMessage('Комментарий не может быть пустым. Пожалуйста, введите ещё раз:');
            $this->next('handleComment');
            return;
        }

        $this->comment = $text;

        try {
            $director = auth()->user();
            if (! $director) {
                $bot->sendMessage('Не удалось определить пользователя. Попробуйте ещё раз.');
                $this->end();
                return;
            }

            DB::transaction(function () use ($director) {
                $req = ExpenseRequest::where('id', $this->requestId)->lockForUpdate()->firstOrFail();

                // проверь статус — подставь нужный вариант enum, если у тебя другой
                if ($req->status !== ExpenseStatus::PENDING->value) {
                    throw new \RuntimeException('Заявка не в статусе ожидания.');
                }

                ExpenseApproval::create([
                    'expense_request_id' => $req->id,
                    'actor_id'           => $director->id,
                    'actor_role'         => 'director',
                    'action'             => ExpenseStatus::APPROVED->value,
                    'comment'            => $this->comment,
                    'created_at'         => now(),
                ]);

                $req->update([
                    'status'               => ExpenseStatus::APPROVED->value,
                    'director_id'          => $director->id,
                    'director_comment'     => $this->comment,
                    'director_approved_at' => now(),
                    'updated_at'           => now(),
                ]);

                AuditLog::create([
                    'table_name' => 'expense_requests',
                    'record_id'  => $req->id,
                    'actor_id'   => $director->id,
                    'action'     => ExpenseStatus::APPROVED->value,
                    'payload'    => [
                        'comment'    => $this->comment,
                        'old_status' => ExpenseStatus::PENDING->value,
                        'new_status' => ExpenseStatus::APPROVED->value,
                    ],
                    'created_at' => now(),
                ]);
            });

            // после коммита — уведомления
            $req = ExpenseRequest::findOrFail($this->requestId);
            $requester = User::find($req->requester_id);

            $bot->editMessageText(
                text: sprintf(
                    "✅ Заявка #%d подтверждена директором\nПользователь: %s (ID: %d)\nСумма: %s %s\nКомментарий: %s",
                    $req->id,
                    $requester->full_name ?? ($requester->login ?? 'Unknown'),
                    $req->requester_id,
                    number_format((float)$req->amount, 2, '.', ' '),
                    $req->currency,
                    $this->comment
                ),
                reply_markup: null,
                message_id: $this->requestMessageId
            );

            if ($requester && $requester->telegram_id) {
                try {
                    $bot->sendMessage(
                        chat_id: $requester->telegram_id,
                        text: "🎉 Ваша заявка #{$req->id} подтверждена директором.\nКомментарий: {$this->comment}"
                    );
                } catch (\Throwable $sendEx) {
                    Log::error('Failed to notify requester after confirm with comment', [
                        'request_id' => $req->id,
                        'message' => $sendEx->getMessage(),
                    ]);
                }
            }

            Log::info(
                "ConfirmWithComment: заявка #{$this->requestId} подтверждена директором {$director->id} с комментом"
            );
        } catch (\Throwable $e) {
            Log::error("Ошибка при подтверждении с комментарием #{$this->requestId}", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $bot->answerCallbackQuery(text: "Ошибка: {$e->getMessage()}", show_alert: true);
        } finally {
            $this->end();
        }
    }

    public function closing(Nutgram $bot)
    {
        $bot->sendMessage("Закрыто подтверждение заявки.");
    }
}
