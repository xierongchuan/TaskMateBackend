<?php

declare(strict_types=1);

/** @var SergiX44\Nutgram\Nutgram $bot */

use SergiX44\Nutgram\Nutgram;
use App\Telegram\Commands\StartCommand;

/*
| Nutgram Handlers
*/

$bot->registerCommand(StartCommand::class);
