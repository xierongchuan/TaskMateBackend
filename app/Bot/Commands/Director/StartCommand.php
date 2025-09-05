<?php

declare(strict_types=1);

namespace App\Bot\Commands\Director;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Enums\Role;
use SergiX44\Nutgram\Nutgram;
use App\Models\User;

/**
 * Start command for directors.
 * Refactored to use base class and follow SOLID principles.
 */

class StartCommand extends BaseCommandHandler
{
    protected string $command = 'start';
    protected ?string $description = '';

    /**
     * Execute the start command logic for directors.
     */
    protected function execute(Nutgram $bot, User $user): void
    {
        $role = Role::tryFromString($user->role)->label();

        $bot->sendMessage(
            'Добро пожаловать ' . $role . ' ' . $user->full_name . '!',
            reply_markup: static::directorMenu()
        );
    }
}
