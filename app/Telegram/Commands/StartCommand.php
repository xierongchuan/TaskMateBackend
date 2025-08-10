<?php

declare(strict_types=1);

namespace App\Telegram\Commands;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Handlers\Type\Command;

class StartCommand extends Command
{
    protected string $command = 'start';

    protected ?string $description = 'A lovely description.';

    public function handle(Nutgram $bot): void
    {
        $bot->sendMessage('This is a command start!');
    }
}
