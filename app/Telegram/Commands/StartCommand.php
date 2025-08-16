<?php

declare(strict_types=1);

namespace App\Telegram\Commands;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Handlers\Type\Command;
use App\Http\Services\VCRM\UserService as VCRMUserService;

class StartCommand extends Command
{
    protected string $command = 'start';

    protected ?string $description = 'A lovely description.';

    public function handle(Nutgram $bot): void
    {
        $users = new VCRMUserService();

        $user = $users->fetchById(9);

        $bot->sendMessage(
            'This is a ' . $user->fullName . '\'s account.'
        );
    }
}
