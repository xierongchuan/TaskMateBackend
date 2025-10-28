<?php

declare(strict_types=1);

namespace App\Bot\Commands\Owner;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Models\User;
use App\Enums\Role;
use SergiX44\Nutgram\Nutgram;

/**
 * Command for owners to view employees
 */
class ViewEmployeesCommand extends BaseCommandHandler
{
    protected string $command = 'viewemployees';
    protected ?string $description = 'Просмотр сотрудников';

    protected function execute(Nutgram $bot, User $user): void
    {
        // Get all users with their dealerships
        $users = User::with('dealership')
            ->orderBy('role')
            ->get();

        $message = "👥 *Сотрудники*\n\n";

        if ($users->isEmpty()) {
            $message .= "Нет сотрудников в системе.\n";
        } else {
            // Group by role
            $groupedByRole = $users->groupBy('role');

            foreach (Role::cases() as $role) {
                $roleUsers = $groupedByRole->get($role->value, collect());

                if ($roleUsers->isNotEmpty()) {
                    $message .= "*{$role->label()}*\n";

                    foreach ($roleUsers as $u) {
                        $dealershipName = $u->dealership?->name ?? 'Не назначен';
                        $message .= "• {$u->name} ({$dealershipName})\n";
                    }

                    $message .= "\n";
                }
            }
        }

        $message .= "💡 Для управления сотрудниками используйте веб-админку.";

        $bot->sendMessage($message, parse_mode: 'Markdown');
    }
}
