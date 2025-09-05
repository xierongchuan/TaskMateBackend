<?php

declare(strict_types=1);

namespace App\Bot\Commands\Director;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Models\User;
use App\Models\ExpenseRequest;
use App\Models\AuditLog;
use App\Enums\ExpenseStatus;
use SergiX44\Nutgram\Nutgram;

/**
 * History command for directors.
 * Shows requests processed by the director and overall company request history.
 */
class HistoryCommand extends BaseCommandHandler
{
    protected string $command = 'director_history';
    protected ?string $description = 'Show expense request history';

    /**
     * Execute the history command logic for directors.
     */
    protected function execute(Nutgram $bot, User $user): void
    {
        // Get requests from the same company
        $requests = ExpenseRequest::with(['requester', 'director', 'accountant'])
            ->where('company_id', $user->company_id)
            ->orderBy('created_at', 'asc')
            ->limit(20) // Limit to last 20 requests
            ->get();

        if ($requests->isEmpty()) {
            $bot->sendMessage(
                "📋 В компании пока нет заявок.",
                reply_markup: static::directorMenu()
            );
            return;
        }

        $message = "📋 *История заявок компании*\n\n";

        foreach ($requests as $request) {
            $status = ExpenseStatus::tryFromString($request->status);
            $statusLabel = $status?->label() ?? $request->status;

            // Format created date
            $createdAt = $request->created_at->format('d.m.Y H:i');

            // Build request line
            $message .= "💼 *Заявка #{$request->id}*\n";
            // $message .= "📝 {$request->title}\n";
            $message .= "👤 Заявитель: {$request->requester->full_name}\n";
            $message .= "💰 " . number_format($request->amount, 2, '.', ' ') . " {$request->currency}\n";
            $message .= "📅 {$createdAt}\n";
            $message .= "📊 Статус: *{$statusLabel}*\n";

            if ($request->accountant) {
                $message .= "💼 Бухгалтер: {$request->accountant->full_name}\n";
            }

            // Add timestamps for processed requests
            if ($request->approved_at) {
                $message .= "✅ Одобрено: " . $request->approved_at->format('d.m.Y H:i') . "\n";
            }

            if ($request->issued_at) {
                $message .= "💸 Выдано: " . $request->issued_at->format('d.m.Y H:i') . "\n";

                // Check if amount was different from approved
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

            // Add director comment if available
            if (!empty($request->director_comment)) {
                $message .= "💬 Комментарий: {$request->director_comment}\n";
            }

            $message .= "\n";
        }

        $message .= "📌 *Показаны последние 20 заявок компании*";

        $bot->sendMessage(
            $message,
            parse_mode: 'Markdown',
            reply_markup: static::directorMenu()
        );
    }
}
