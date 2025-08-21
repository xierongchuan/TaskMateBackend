<?php

declare(strict_types=1);

/** @var SergiX44\Nutgram\Nutgram $bot */

use App\Bot\Callbacks\ExpenseConfirmCallback;
use App\Bot\Callbacks\ExpenseDeclineCallback;
use App\Enums\Role;
use SergiX44\Nutgram\Nutgram;
use App\Bot\Middleware\AuthUser;
use App\Bot\Middleware\RoleMiddleware;
use App\Bot\Dispatchers\StartConversationDispatcher;
use App\Bot\Conversations\User\RequestExpenseConversation;
use App\Bot\Conversations\Director\ConfirmWithCommentConversation;

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

// Director Callbacks
$bot->onCallbackQueryData(
    'expense:confirm:{id}',
    ExpenseConfirmCallback::class
)
->middleware(new RoleMiddleware([Role::DIRECTOR->value]))
->middleware(AuthUser::class);

$bot->onCallbackQueryData(
    'expense:decline:{id}',
    ExpenseDeclineCallback::class
)
->middleware(new RoleMiddleware([Role::DIRECTOR->value]))
->middleware(AuthUser::class);

$bot->onCallbackQueryData(
    'expense:confirm_with_comment:{id}',
    ConfirmWithCommentConversation::class
)
->middleware(new RoleMiddleware([Role::DIRECTOR->value]))
->middleware(AuthUser::class);

// $bot->onCallbackQueryData('expense:confirm_with_comment:{id}', function (SergiX44\Nutgram\Nutgram $bot, string $id) {
//     // чтобы кнопка не висела
//     $bot->answerCallbackQuery();

//     ConfirmWithCommentConversation::begin($bot, data: ['requestId' => (int)$id]);
// });
