<?php

declare(strict_types=1);

/** @var SergiX44\Nutgram\Nutgram $bot */

use SergiX44\Nutgram\Nutgram;

/*
| Nutgram Handlers
*/

$bot->onCommand('start', function (Nutgram $bot) {
    $bot->sendMessage('Hello, world!');
})->description('The start command!');
