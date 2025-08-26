<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Models\ExpenseApproval;
use App\Models\ExpenseRequest;
use App\Models\AuditLog;
use App\Traits\KeyboardTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Enums\ExpenseStatus;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use Throwable;
use App\Models\User;

class ExpenseService
{
    /** Director approves a request */
    public function directorApprove(int $requestId, int $directorId, ?string $comment = null): void
    {
        DB::transaction(function () use ($requestId, $directorId, $comment) {
            $req = ExpenseRequest::where('id', $requestId)->lockForUpdate()->firstOrFail();

            if ($req->status !== 'pending_director') {
                throw new \RuntimeException('Неверный статус заявки для подтверждения');
            }

            ExpenseApproval::create([
                'expense_request_id' => $req->id,
                'actor_id' => $directorId,
                'actor_role' => 'director',
                'action' => 'approved',
                'comment' => $comment,
                'created_at' => now(),
            ]);

            $oldStatus = $req->status;

            $req->update([
                'status' => 'director_approved',
                'director_id' => $directorId,
                'director_comment' => $comment,
                'director_approved_at' => now(),
            ]);

            AuditLog::create([
                'table_name' => 'expense_requests',
                'record_id' => $req->id,
                'actor_id' => $directorId,
                'action' => 'director_approved',
                'payload' => ['old_status' => $oldStatus, 'new_status' => 'director_approved'],
                'created_at' => now(),
            ]);
        });
    }

    /** Create request (with audit) */
    public static function createRequest(
        Nutgram $bot,
        User $requester,
        string $description,
        float $amount,
        string $currency = 'UZS'
    ): null|int {
        try {
            // 1) Создаём запись в транзакции
            $requestId = DB::transaction(function () use ($requester, $description, $amount, $currency) {
                $req = ExpenseRequest::create([
                    'requester_id' => $requester->id,
                    'description' => $description,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => ExpenseStatus::PENDING->value,
                    'company_id' => $requester->company_id,
                ]);

                AuditLog::create([
                    'table_name' => 'expense_requests',
                    'record_id' => $req->id,
                    'actor_id' => $requester->id,
                    'action' => 'insert',
                    'payload' => ['amount' => $amount, 'currency' => $currency],
                    'created_at' => now(),
                ]);

                Log::info("Заявка #{$req->id} успешно создана пользователем {$requester->id}");

                return $req->id;
            });

            // 2) Если транзакция успешно закоммичена (вернулся id) - уведомляем директора(ов)
            if ($requestId !== null) {
                try {
                    // Найдём заявителя
                    if (! $requester) {
                        Log::warning(
                            'createRequest: requester not found',
                            ['requester_id' => $requester->id, 'request_id' => $requestId]
                        );
                        return $requestId;
                    }

                    // company_id предполагается на модели User
                    $companyId = $requester->company_id ?? null;
                    if ($companyId === null) {
                        Log::warning(
                            'createRequest: requester has no company_id',
                            ['requester_id' => $requester->id, 'request_id' => $requestId]
                        );
                        return $requestId;
                    }

                    $director = User::where('company_id', $companyId)
                        ->where('role', Role::DIRECTOR->value)
                        ->whereNotNull('telegram_id')
                        ->first();

                    if (empty($director)) {
                        Log::info(
                            'createRequest: no director to notify',
                            ['company_id' => $companyId, 'request_id' => $requestId]
                        );
                        return $requestId;
                    }

                    // подготовим текст и inline-кнопки
                    $message = sprintf(
                        "Новая заявка #%d\nПользователь: %s (ID: %d)\nСумма: %s %s\nКомментарий: %s",
                        $requestId,
                        $requester->full_name ?? ($requester->login ?? 'Unknown'),
                        $requester->id,
                        number_format($amount, 2, '.', ' '),
                        $currency,
                        $description ?: '-'
                    );

                    $confirmData = "expense:confirm:{$requestId}";
                    $confirmWithCommentData = "expense:confirm_with_comment:{$requestId}";
                    $cancelData  = "expense:decline:{$requestId}";
                    $inline = KeyboardTrait::inlineConfirmCommentDecline(
                        $confirmData,
                        $confirmWithCommentData,
                        $cancelData
                    );

                    // Отправляем всем директору (логируем результат)
                    try {
                        // Nutgram sendMessage: chat_id можно указать как first param? используем опцию chat_id
                        $bot->sendMessage(
                            $message,
                            chat_id: $director->telegram_id,
                            reply_markup: $inline,
                        );

                        Log::info('createRequest: notified director', [
                            'director_id' => $director->id,
                            'director_tg' => $director->telegram_id,
                            'request_id' => $requestId,
                        ]);
                    } catch (Throwable $sendEx) {
                        Log::error('createRequest: failed sending to director', [
                            'director_id' => $director->id,
                            'director_tg' => $director->telegram_id,
                            'request_id' => $requestId,
                            'message' => $sendEx->getMessage(),
                            'trace' => $sendEx->getTraceAsString(),
                        ]);
                    }
                } catch (Throwable $notifyEx) {
                    // Защищаемся: любые ошибки уведомлений не должны ломать основной процесс
                    Log::error('createRequest: notification error', [
                        'request_id' => $requestId,
                        'message' => $notifyEx->getMessage(),
                        'trace' => $notifyEx->getTraceAsString(),
                    ]);
                }
            }

            return $requestId;
        } catch (Throwable $e) {
            Log::error('Ошибка при создании заявки', [
                'requester_id' => $requester->id,
                'amount'       => $amount,
                'currency'     => $currency,
                'message'      => $e->getMessage(),
                'trace'        => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    public static function sendToAccountant(
        Nutgram $bot,
        User $requester,
        int $requestId,
        float $amount,
        string $currency
    ) {
        $accountant = User::where('company_id', $requester->company_id)
            ->where('role', Role::ACCOUNTANT->value)->first();

        $confirmData = "expense:issued:{$requestId}";

        $bot->sendMessage(
            chat_id: $accountant->telegram_id,
            text: "Заявка #{$requestId} подтверждена "
                . "директором.\nСумма: " . number_format((float) $amount, 2, '.', ' ') . " $currency\n"
                . "Ожидает выдачи указанной суммы "
                . $requester->full_name . ' (ID: ' . $requester->id . ')',
            reply_markup: KeyboardTrait::inlineConfirmIssued($confirmData)
        );
    }

    /** Delete request (with audit) */
    public function deleteRequest(int $requestId, int $actorId, ?string $reason = null): void
    {
        DB::transaction(function () use ($requestId, $actorId, $reason) {
            $req = ExpenseRequest::where('id', $requestId)->lockForUpdate()->firstOrFail();

            $req->delete(); // ON DELETE CASCADE должен удалить связанные approvals

            AuditLog::create([
                'table_name' => 'expense_requests',
                'record_id' => $requestId,
                'actor_id' => $actorId,
                'action' => 'delete',
                'payload' => ['reason' => $reason],
                'created_at' => now(),
            ]);
        });
    }
}
