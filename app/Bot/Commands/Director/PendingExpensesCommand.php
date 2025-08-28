<?php

declare(strict_types=1);

namespace App\Bot\Commands\Director;

use App\Enums\ExpenseStatus;
use App\Enums\Role;
use App\Models\ExpenseRequest;
use App\Traits\KeyboardTrait;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use Psr\Log\LoggerInterface;
use App\Models\User;
use Throwable;

class PendingExpensesCommand
{
    public function __invoke(Nutgram $bot): void
    {
        try {
            $director = auth()->user();

            $pendingExpenses = ExpenseRequest::when('requester')
                ->where('company_id', $director->company_id)
                ->where('status', ExpenseStatus::PENDING->value)
                ->get();

            foreach ($pendingExpenses as $key => $expense) {
                $message = sprintf(
                    "Заявка #%d\nПользователь: %s (ID: %d)\nСумма: %s %s\nКомментарий: %s",
                    $expense->id,
                    $expense->requester->full_name ?? ($expense->requester->login ?? 'Unknown'),
                    $expense->requester_id,
                    number_format((float) $expense->amount, 2, '.', ' '),
                    $expense->currency,
                    $expense->description ?: '-'
                );

                $confirmData = "expense:confirm:{$expense->id}";
                $confirmWithCommentData = "expense:confirm_with_comment:{$expense->id}";
                $cancelData  = "expense:decline:{$expense->id}";
                $inline = KeyboardTrait::inlineConfirmCommentDecline(
                    $confirmData,
                    $confirmWithCommentData,
                    $cancelData
                );

                $bot->sendMessage(
                    $message,
                    reply_markup: $inline,
                );
            }
        } catch (Throwable $e) {
            Log::error('director.pendingexpense.command.failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $bot->sendMessage('Произошла ошибка при запуске. Попробуйте позже.');
        }
    }
}
