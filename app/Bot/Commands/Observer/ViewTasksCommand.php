<?php

declare(strict_types=1);

namespace App\Bot\Commands\Observer;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Models\User;
use App\Models\Task;
use SergiX44\Nutgram\Nutgram;

/**
 * Command for observers to view tasks (read-only)
 */
class ViewTasksCommand extends BaseCommandHandler
{
    protected string $command = 'observetasks';
    protected ?string $description = 'Просмотр задач (только чтение)';

    protected function execute(Nutgram $bot, User $user): void
    {
        // Get user's dealerships
        $dealershipIds = [$user->dealership_id];

        // Get tasks for observer's dealerships
        $tasks = Task::whereIn('dealership_id', $dealershipIds)
            ->where('is_active', true)
            ->with(['assignments.user', 'assignments.responses'])
            ->latest()
            ->take(10)
            ->get();

        $message = "👀 *Просмотр задач (только чтение)*\n\n";

        if ($tasks->isEmpty()) {
            $message .= "Нет активных задач.\n";
        } else {
            foreach ($tasks as $task) {
                $message .= "*{$task->title}*\n";

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

        $message .= "💡 У вас доступ только для просмотра.";

        $bot->sendMessage($message, parse_mode: 'Markdown');
    }
}
