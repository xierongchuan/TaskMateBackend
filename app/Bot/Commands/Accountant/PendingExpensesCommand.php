<?php

declare(strict_types=1);

namespace App\Bot\Commands\Accountant;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Enums\ExpenseStatus;
use App\Models\ExpenseRequest;
use SergiX44\Nutgram\Nutgram;
use App\Models\User;

/**
 * Command to display pending expenses for accountants (approved but not issued).
 * Refactored to use base class and follow SOLID principles.
 */
class PendingExpensesCommand extends BaseCommandHandler
{
    protected string $command = 'pending_expenses_accountant';
    protected ?string $description = 'Show approved expense requests waiting for issuance';

    /**
     * Execute the pending expenses command logic for accountants.
     */
    protected function execute(Nutgram $bot, User $user): void
    {
        $approvedExpenses = ExpenseRequest::with(['requester', 'director'])
            ->where('company_id', $user->company_id)
            ->where('status', ExpenseStatus::APPROVED->value)
            ->orderBy('approved_at', 'asc')
            ->get();

        if ($approvedExpenses->isEmpty()) {
            $bot->sendMessage('💰 Нет ожидающих выдачи заявок.');
            return;
        }

        foreach ($approvedExpenses as $expense) {
            $message = sprintf(
                "Заявка #%d\nПользователь: %s (ID: %d)\nСумма: %s %s\nОписание: %s\nПодтверждена: %s",
                $expense->id,
                $expense->requester->full_name ?? ($expense->requester->login ?? 'Unknown'),
                $expense->requester_id,
                number_format((float) $expense->amount, 2, '.', ' '),
                $expense->currency,
                $expense->description ?: '-',
                $expense->approved_at?->format('d.m.Y H:i') ?? '-'
            );

            // Add director's comment if available
            if ($expense->director_comment && $expense->director_comment !== '-') {
                $message .= "\nКомментарий директора: {$expense->director_comment}";
            }

            $keyboard = static::inlineConfirmIssued("expense:issued:{$expense->id}");

            $bot->sendMessage(
                $message,
                reply_markup: $keyboard,
            );
        }
    }
}
