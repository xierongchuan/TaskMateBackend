<?php

declare(strict_types=1);

/** @var SergiX44\Nutgram\Nutgram $bot */

use SergiX44\Nutgram\Nutgram;
use App\Bot\Conversations\Guest\StartCommand;
use App\Bot\Middleware\AuthUser;
use App\Bot\Middleware\RoleMiddleware;
use App\Bot\Dispatchers\StartConversationDispatcher;
use App\Bot\Conversations\User\RequestExpenseConversation;

/*
| Nutgram Handlers
*/

$bot->onCommand(
    'start',
    StartConversationDispatcher::class
);

// Users Middleware
$bot->onText(
    '📝 Создать заявку',
    RequestExpenseConversation::class
)
->middleware(new RoleMiddleware(['user']))
->middleware(AuthUser::class);
