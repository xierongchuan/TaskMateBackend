<?php

declare(strict_types=1);

namespace App\Bot\Commands\Observer;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Enums\Role;
use SergiX44\Nutgram\Nutgram;
use App\Models\User;

class StartCommand extends BaseCommandHandler
{
    protected string $command = 'start';
    protected ?string $description = '';

    protected function execute(Nutgram $bot, User $user): void
    {
        $role = Role::tryFromString($user->role)->label();

        $bot->sendMessage(
            'Добро пожаловать ' . $role . ' ' . $user->full_name . '!',
            reply_markup: static::observerMenu()
        );
    }
}
