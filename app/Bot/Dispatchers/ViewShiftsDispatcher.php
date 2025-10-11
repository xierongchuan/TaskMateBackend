<?php

declare(strict_types=1);

namespace App\Bot\Dispatchers;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Enums\Role;
use App\Models\User;
use SergiX44\Nutgram\Nutgram;

/**
 * Dispatcher for viewing shifts based on user role
 */
class ViewShiftsDispatcher extends BaseCommandHandler
{
    protected string $command = 'shifts';
    protected ?string $description = 'Просмотр смен';

    protected function execute(Nutgram $bot, User $user): void
    {
        // Route based on role
        match ($user->role) {
            Role::OWNER->value => (new \App\Bot\Commands\Owner\ViewShiftsCommand())->handle($bot),
            Role::MANAGER->value => (new \App\Bot\Commands\Manager\ViewShiftsCommand())->handle($bot),
            Role::OBSERVER->value => (new \App\Bot\Commands\Observer\ViewShiftsCommand())->handle($bot),
            default => $bot->sendMessage('Эта функция недоступна для вашей роли.')
        };
    }
}
