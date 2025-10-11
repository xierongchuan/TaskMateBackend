<?php

declare(strict_types=1);

/** @var SergiX44\Nutgram\Nutgram $bot */

use SergiX44\Nutgram\Nutgram;
use App\Bot\Middleware\AuthUser;
use App\Bot\Middleware\ConversationGuard;
use App\Bot\Middleware\RoleMiddleware;
use App\Bot\Dispatchers\StartConversationDispatcher;

/*
| Nutgram Handlers
*/

$bot->onCommand(
    'start',
    StartConversationDispatcher::class
);

// Shift management commands
$bot->middleware(AuthUser::class)->group(function (Nutgram $bot) {
    // Employee commands - shift management
    $bot->onCommand('openshift', \App\Bot\Commands\Employee\OpenShiftCommand::class);
    $bot->onCommand('closeshift', \App\Bot\Commands\Employee\CloseShiftCommand::class);

    // Handle text button presses for shift management
    $bot->onText('🔓 Открыть смену', \App\Bot\Commands\Employee\OpenShiftCommand::class);
    $bot->onText('🔒 Закрыть смену', \App\Bot\Commands\Employee\CloseShiftCommand::class);

    // Task response handlers via callback queries
    $bot->onCallbackQueryData('task_ok_{taskId}', \App\Bot\Handlers\TaskResponseHandler::class . '@handleOk');
    $bot->onCallbackQueryData('task_done_{taskId}', \App\Bot\Handlers\TaskResponseHandler::class . '@handleDone');
    $bot->onCallbackQueryData('task_postpone_{taskId}', \App\Bot\Handlers\TaskResponseHandler::class . '@handlePostpone');
});
