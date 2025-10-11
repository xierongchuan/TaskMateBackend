<?php

declare(strict_types=1);

namespace App\Bot\Commands\Owner;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Models\User;
use App\Models\Task;
use SergiX44\Nutgram\Nutgram;

/**
 * Command for owners to view tasks across all dealerships
 */
class ViewTasksCommand extends BaseCommandHandler
{
    protected string $command = 'ownertasks';
    protected ?string $description = 'Просмотр всех задач';

    protected function execute(Nutgram $bot, User $user): void
    {
        // Get tasks across all dealerships
        $tasks = Task::where('is_active', true)
            ->with(['assignments.user', 'assignments.responses', 'autoDealership'])
            ->latest()
            ->take(10)
            ->get();

        $message = "📋 *Все задачи*\n\n";

        if ($tasks->isEmpty()) {
            $message .= "Нет активных задач.\n";
        } else {
            foreach ($tasks as $task) {
                $dealership = $task->autoDealership?->name ?? 'N/A';
                $message .= "*{$task->title}* ({$dealership})\n";

                // Count statuses
                $completed = 0;
                $postponed = 0;
                $pending = 0;

                foreach ($task->assignments as $assignment) {
                    $latestResponse = $assignment->responses->sortByDesc('created_at')->first();
                    if ($latestResponse) {
                        if ($latestResponse->status === 'completed') {
                            $completed++;
                        } elseif ($latestResponse->status === 'postponed') {
                            $postponed++;
                        } else {
                            $pending++;
                        }
                    } else {
                        $pending++;
                    }
                }

                $total = $task->assignments->count();
                $message .= "Назначено: {$total} | ✅ {$completed} | ⏳ {$postponed} | ⏸️ {$pending}\n\n";
            }
        }

        $message .= "💡 Для управления задачами используйте веб-админку.";

        $bot->sendMessage($message, parse_mode: 'Markdown');
    }
}
