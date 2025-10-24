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
$bot->group(function (Nutgram $bot) {
    // Employee commands - shift management
    $bot->onCommand('openshift', \App\Bot\Commands\Employee\OpenShiftCommand::class)->middleware(AuthUser::class);
    $bot->onCommand('closeshift', \App\Bot\Commands\Employee\CloseShiftCommand::class)->middleware(AuthUser::class);

    // Handle text button presses for shift management
    $bot->onText('🔓 Открыть смену', \App\Bot\Commands\Employee\OpenShiftCommand::class)->middleware(AuthUser::class);
    $bot->onText('🔒 Закрыть смену', \App\Bot\Commands\Employee\CloseShiftCommand::class)->middleware(AuthUser::class);

    // Role-based view commands (using dispatchers for shared buttons)
    $bot->onText('📊 Смены', \App\Bot\Dispatchers\ViewShiftsDispatcher::class)->middleware(AuthUser::class);
    $bot->onText('📋 Задачи', \App\Bot\Dispatchers\ViewTasksDispatcher::class)->middleware(AuthUser::class);

    // Observer-specific buttons
    $bot->onText('👀 Просмотр смен', \App\Bot\Commands\Observer\ViewShiftsCommand::class)->middleware(AuthUser::class);
    $bot->onText('📋 Просмотр задач', \App\Bot\Commands\Observer\ViewTasksCommand::class)->middleware(AuthUser::class);

    // Owner-specific buttons
    $bot->onText('🏢 Салоны', \App\Bot\Commands\Owner\ViewDealershipsCommand::class)->middleware(AuthUser::class);
    $bot->onText('👥 Сотрудники', \App\Bot\Commands\Owner\ViewEmployeesCommand::class)->middleware(AuthUser::class);

    // Task response handlers via callback queries
    $bot->onCallbackQueryData(
        'task_ok_{taskId}',
        \App\Bot\Handlers\TaskResponseHandler::class . '@handleOk'
    )->middleware(AuthUser::class);
    $bot->onCallbackQueryData(
        'task_done_{taskId}',
        \App\Bot\Handlers\TaskResponseHandler::class . '@handleDone'
    )->middleware(AuthUser::class);
    $bot->onCallbackQueryData(
        'task_postpone_{taskId}',
        \App\Bot\Handlers\TaskResponseHandler::class . '@handlePostpone'
    )->middleware(AuthUser::class);
});
