<?php

declare(strict_types=1);

/** @var SergiX44\Nutgram\Nutgram $bot */

use App\Conversations\RequestExpenseConversation;
use SergiX44\Nutgram\Nutgram;
use App\Bot\Conversations\Guest\StartCommand;
use App\Bot\Middleware\AuthUser;
use App\Bot\Middleware\RoleMiddleware;
use App\Bot\Dispatchers\StartConversationDispatcher;

/*
| Nutgram Handlers
*/

$bot->onCommand(
    'start',
    StartConversationDispatcher::class
);

$bot->onCommand(
    'expense',
    RequestExpenseConversation::class
)
->middleware(new RoleMiddleware(['user']))
->middleware(AuthUser::class);
