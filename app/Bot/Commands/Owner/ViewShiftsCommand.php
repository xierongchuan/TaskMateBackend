<?php

declare(strict_types=1);

namespace App\Bot\Commands\Owner;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Models\User;
use App\Models\Shift;
use SergiX44\Nutgram\Nutgram;
use Carbon\Carbon;

/**
 * Command for owners to view shifts across all dealerships
 */
class ViewShiftsCommand extends BaseCommandHandler
{
    protected string $command = 'ownershifts';
    protected ?string $description = 'Просмотр всех смен';

    protected function execute(Nutgram $bot, User $user): void
    {
        // Get active shifts for today across all dealerships
        $todayShifts = Shift::whereNull('actual_end')
            ->whereDate('actual_start', Carbon::today())
            ->with(['user', 'autoDealership'])
            ->get();

        // Get completed shifts for today
        $completedShifts = Shift::whereNotNull('actual_end')
            ->whereDate('actual_start', Carbon::today())
            ->with(['user', 'autoDealership'])
            ->get();

        $message = "📊 *Все смены сегодня*\n\n";

        if ($todayShifts->isEmpty() && $completedShifts->isEmpty()) {
            $message .= "Нет смен на сегодня.\n";
        } else {
            if ($todayShifts->isNotEmpty()) {
                $message .= "*Активные смены:*\n";
                foreach ($todayShifts as $shift) {
                    $startTime = $shift->actual_start->format('H:i');
                    $status = $shift->status === 'late' ? '🔴 Опоздание' : '🟢 Вовремя';
                    $dealership = $shift->autoDealership?->name ?? 'N/A';
                    $message .= "• {$shift->user->name} - {$dealership} ({$startTime}) - {$status}\n";
                }
                $message .= "\n";
            }

            if ($completedShifts->isNotEmpty()) {
                $message .= "*Завершённые смены:*\n";
                foreach ($completedShifts as $shift) {
                    $startTime = $shift->actual_start->format('H:i');
                    $endTime = $shift->actual_end?->format('H:i') ?? 'N/A';
                    $dealership = $shift->autoDealership?->name ?? 'N/A';
                    $message .= "• {$shift->user->name} - {$dealership} ({$startTime} - {$endTime})\n";
                }
            }
        }

        $message .= "\n💡 Для полного функционала используйте веб-админку.";

        $bot->sendMessage($message, parse_mode: 'Markdown');
    }
}
