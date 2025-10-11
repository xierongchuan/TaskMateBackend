<?php

declare(strict_types=1);

namespace App\Bot\Dispatchers;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Enums\Role;
use App\Models\User;
use SergiX44\Nutgram\Nutgram;

/**
 * Dispatcher for viewing tasks based on user role
 */
class ViewTasksDispatcher extends BaseCommandHandler
{
    protected string $command = 'tasks';
    protected ?string $description = 'Просмотр задач';

    protected function execute(Nutgram $bot, User $user): void
    {
        // Route based on role
        match ($user->role) {
            Role::OWNER->value => (new \App\Bot\Commands\Owner\ViewTasksCommand())->handle($bot),
            Role::MANAGER->value => (new \App\Bot\Commands\Manager\ViewTasksCommand())->handle($bot),
            Role::OBSERVER->value => (new \App\Bot\Commands\Observer\ViewTasksCommand())->handle($bot),
            default => $bot->sendMessage('Эта функция недоступна для вашей роли.')
        };
    }
}
