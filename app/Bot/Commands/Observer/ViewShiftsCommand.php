<?php

declare(strict_types=1);

namespace App\Bot\Commands\Observer;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Models\User;
use App\Models\Shift;
use SergiX44\Nutgram\Nutgram;
use Carbon\Carbon;

/**
 * Command for observers to view shifts (read-only)
 */
class ViewShiftsCommand extends BaseCommandHandler
{
    protected string $command = 'observeshifts';
    protected ?string $description = 'Просмотр смен (только чтение)';

    protected function execute(Nutgram $bot, User $user): void
    {
        // Get user's dealerships (observers can view their assigned dealership)
        $dealershipIds = [$user->auto_dealership_id];

        // Get active shifts for today
        $todayShifts = Shift::whereIn('auto_dealership_id', $dealershipIds)
            ->whereNull('actual_end')
            ->whereDate('actual_start', Carbon::today())
            ->with('user')
            ->get();

        // Get completed shifts for today
        $completedShifts = Shift::whereIn('auto_dealership_id', $dealershipIds)
            ->whereNotNull('actual_end')
            ->whereDate('actual_start', Carbon::today())
            ->with('user')
            ->get();

        $message = "👀 *Просмотр смен (только чтение)*\n\n";

        if ($todayShifts->isEmpty() && $completedShifts->isEmpty()) {
            $message .= "Нет смен на сегодня.\n";
        } else {
            if ($todayShifts->isNotEmpty()) {
                $message .= "*Активные смены:*\n";
                foreach ($todayShifts as $shift) {
                    $startTime = $shift->actual_start->format('H:i');
                    $status = $shift->status === 'late' ? '🔴 Опоздание' : '🟢 Вовремя';
                    $message .= "• {$shift->user->name} ({$startTime}) - {$status}\n";
                }
                $message .= "\n";
            }

            if ($completedShifts->isNotEmpty()) {
                $message .= "*Завершённые смены:*\n";
                foreach ($completedShifts as $shift) {
                    $startTime = $shift->actual_start->format('H:i');
                    $endTime = $shift->actual_end?->format('H:i') ?? 'N/A';
                    $message .= "• {$shift->user->name} ({$startTime} - {$endTime})\n";
                }
            }
        }

        $message .= "\n💡 У вас доступ только для просмотра.";

        $bot->sendMessage($message, parse_mode: 'Markdown');
    }
}
