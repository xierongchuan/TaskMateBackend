<?php

declare(strict_types=1);

namespace App\Bot\Commands\User;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Models\User;
use App\Models\ExpenseRequest;
use App\Models\AuditLog;
use App\Enums\ExpenseStatus;
use SergiX44\Nutgram\Nutgram;

/**
 * History command for users.
 * Shows the user's expense request history in a concise format.
 */
class HistoryCommand extends BaseCommandHandler
{
    protected string $command = 'user_history';
    protected ?string $description = 'Show expense request history';

    /**
     * Execute the history command logic for users.
     */
    protected function execute(Nutgram $bot, User $user): void
    {
        $requests = ExpenseRequest::with(['director', 'accountant'])
            ->where('requester_id', $user->id)
            ->orderBy('created_at', 'asc')
            ->limit(20) // Limit to last 20 requests
            ->get();

        if ($requests->isEmpty()) {
            $bot->sendMessage(
                "📋 У вас пока нет заявок.\n\nСоздайте первую заявку с помощью кнопки '📝 Создать заявку'",
                reply_markup: static::userMenu()
            );
            return;
        }

        $message = "📋 *История ваших заявок*\n\n";

        foreach ($requests as $request) {
            $status = ExpenseStatus::tryFromString($request->status);
            $statusLabel = $status?->label() ?? $request->status;

            // Format created date
            $createdAt = $request->created_at->format('d.m.Y H:i');

            // Build request line
            $message .= "💼 *Заявка #{$request->id}*\n";
            // $message .= "📝 {$request->title}\n";
            $message .= "💰 " . number_format($request->amount, 2, '.', ' ') . " {$request->currency}\n";
            $message .= "📅 {$createdAt}\n";
            $message .= "📊 Статус: *{$statusLabel}*\n";

            // Add processor information if available
            if ($request->director) {
                $message .= "👔 Директор: {$request->director->full_name}\n";
            }

            if ($request->accountant) {
                $message .= "💼 Бухгалтер: {$request->accountant->full_name}\n";
            }

            // Add timestamps for processed requests
            if ($request->approved_at) {
                $message .= "✅ Одобрено: " . $request->approved_at->format('d.m.Y H:i') . "\n";
            }

            if ($request->issued_at) {
                $message .= "💸 Выдано: " . $request->issued_at->format('d.m.Y H:i') . "\n";

                // Check if amount was different from approved (for users, only show the fact, not comments)
                $auditLog = AuditLog::where('table_name', 'expense_requests')
                    ->where('record_id', $request->id)
                    ->where('action', ExpenseStatus::ISSUED->value)
                    ->first();

                if ($auditLog && isset($auditLog->payload['original_amount'], $auditLog->payload['issued_amount'])) {
                    $originalAmount = (float)$auditLog->payload['original_amount'];
                    $issuedAmount = (float)$auditLog->payload['issued_amount'];

                    if (abs($originalAmount - $issuedAmount) > 0.01) { // Account for float precision
                        $formattedIssuedAmount = number_format($issuedAmount, 2, '.', ' ');
                        $message .= "⚠️ Выдана иная сумма: {$formattedIssuedAmount} {$request->currency}\n";
                    }
                }
            }

            $message .= "\n";
        }

        $message .= "📌 *Показаны последние 20 заявок*";

        $bot->sendMessage(
            $message,
            parse_mode: 'Markdown',
            reply_markup: static::userMenu()
        );
    }
}
