<?php

declare(strict_types=1);

namespace App\Telegram\Commands;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Handlers\Type\Command;
use App\Http\Services\VCRM\UserService;

class StartCommand extends Command
{
    protected string $command = 'start';

    protected ?string $description = 'A lovely description.';

    public function handle(Nutgram $bot): void
    {
        $users = new UserService();

        $data = $users->fetchById(5);

        $bot->sendMessage(
            'This is a ' . $data->full_name . '\'s account.'
        );
    }
}
