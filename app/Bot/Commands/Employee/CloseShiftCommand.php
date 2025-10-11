<?php

declare(strict_types=1);

namespace App\Bot\Commands\Employee;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Models\Shift;
use App\Models\User;
use App\Models\Task;
use App\Models\TaskResponse;
use SergiX44\Nutgram\Nutgram;
use Carbon\Carbon;

/**
 * Command for employees to close their shift
 */
class CloseShiftCommand extends BaseCommandHandler
{
    protected string $command = 'closeshift';
    protected ?string $description = 'Закрыть смену';

    protected function execute(Nutgram $bot, User $user): void
    {
        // Find open shift
        $openShift = Shift::where('user_id', $user->id)
            ->where('status', 'open')
            ->whereNull('shift_end')
            ->first();

        if (!$openShift) {
            $bot->sendMessage('⚠️ У вас нет открытой смены.');
            return;
        }

        // Close the shift
        $openShift->shift_end = Carbon::now();
        $openShift->status = 'closed';
        $openShift->save();

        // Check for incomplete tasks during this shift
        $incompleteTasks = Task::whereHas('assignments', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
        ->where('is_active', true)
        ->whereDoesntHave('responses', function ($query) use ($user, $openShift) {
            $query->where('user_id', $user->id)
                ->where('status', 'completed')
                ->whereBetween('responded_at', [
                    $openShift->shift_start,
                    $openShift->shift_end
                ]);
        })
        ->count();

        $message = '✅ Смена закрыта в ' . Carbon::now()->format('H:i d.m.Y');

        if ($incompleteTasks > 0) {
            $message .= "\n\n⚠️ Незавершённых задач: " . $incompleteTasks;
        }

        $bot->sendMessage($message, reply_markup: static::employeeMenu());
    }
}
