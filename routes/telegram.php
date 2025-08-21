<?php

declare(strict_types=1);

/** @var SergiX44\Nutgram\Nutgram $bot */

use App\Bot\Handlers\ExpenseConfirmCallback;
use App\Bot\Handlers\ExpenseRequestHandler;
use App\Enums\Role;
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
->middleware(new RoleMiddleware([Role::USER->value]))
->middleware(AuthUser::class);

$bot->onCallbackQueryData(
    'expense:confirm:{id}',
    ExpenseConfirmCallback::class
)
->middleware(new RoleMiddleware([Role::DIRECTOR->value]))
->middleware(AuthUser::class);

// Регистрируем хендлеры заявок
ExpenseRequestHandler::register($bot);
