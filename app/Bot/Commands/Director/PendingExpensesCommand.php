<?php

declare(strict_types=1);

namespace App\Bot\Commands\Director;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Enums\ExpenseStatus;
use App\Models\ExpenseRequest;
use SergiX44\Nutgram\Nutgram;
use App\Models\User;

/**
 * Command to display pending expenses for directors.
 * Refactored to use base class and follow SOLID principles.
 */

class PendingExpensesCommand extends BaseCommandHandler
{
    protected string $command = 'pending_expenses';
    protected ?string $description = 'Show pending expense requests';

    /**
     * Execute the pending expenses command logic.
     */
    protected function execute(Nutgram $bot, User $user): void
    {
        $pendingExpenses = ExpenseRequest::with('requester')
            ->where('company_id', $user->company_id)
            ->where('status', ExpenseStatus::PENDING->value)
            ->get();

        if ($pendingExpenses->isEmpty()) {
            $bot->sendMessage('📋 Нет ожидающих заявок.');
            return;
        }

        foreach ($pendingExpenses as $expense) {
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
            $cancelData = "expense:decline:{$expense->id}";

            $inline = static::inlineConfirmCommentDecline(
                $confirmData,
                $confirmWithCommentData,
                $cancelData
            );

            $bot->sendMessage(
                $message,
                reply_markup: $inline,
            );
        }
    }
}
