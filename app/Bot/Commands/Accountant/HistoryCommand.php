<?php

declare(strict_types=1);

namespace App\Bot\Commands\Accountant;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Models\User;
use App\Models\ExpenseRequest;
use App\Models\AuditLog;
use App\Models\ExpenseApproval;
use App\Enums\ExpenseStatus;
use SergiX44\Nutgram\Nutgram;

/**
 * History command for accountants.
 * Shows requests processed by the accountant and approved requests requiring issuance.
 */
class HistoryCommand extends BaseCommandHandler
{
    protected string $command = 'accountant_history';
    protected ?string $description = 'Show expense request history';

    /**
     * Execute the history command logic for accountants.
     */
    protected function execute(Nutgram $bot, User $user): void
    {
        // Get requests from the same company that reached accountant stage
        $requests = ExpenseRequest::with(['requester', 'director', 'accountant'])
            ->where('company_id', $user->company_id)
            ->whereIn('status', ['approved', 'issued'])
            ->orderBy('created_at', 'asc')
            ->limit(20) // Limit to last 20 requests
            ->get();

        if ($requests->isEmpty()) {
            $bot->sendMessage(
                "📋 Пока нет заявок для обработки.",
                reply_markup: static::accountantMenu()
            );
            return;
        }

        $message = "📋 *История заявок (одобренные/выданные)*\n\n";

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

            // Add processor information
            // if ($request->director) {
            //     $message .= "👔 Директор: {$request->director->full_name}\n";
            // }

            // if ($request->accountant) {
            //     $accountantName = $request->accountant->full_name;
            //     // Highlight if current user processed it
            //     if ($request->accountant_id === $user->id) {
            //         $accountantName = "💼 *{$accountantName}* (Вы)";
            //     }
            //     $message .= "💼 Бухгалтер: {$accountantName}\n";
            // }

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
                $message .= "💬 Комментарий директора: {$request->director_comment}\n";
            }

            $message .= "\n";
        }

        $message .= "📌 *Показаны последние 20 заявок (одобренные/выданные)*";

        $bot->sendMessage(
            $message,
            parse_mode: 'Markdown',
            reply_markup: static::accountantMenu()
        );
    }
}
