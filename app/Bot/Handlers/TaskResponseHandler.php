<?php

declare(strict_types=1);

namespace App\Bot\Handlers;

use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

/**
 * Handler for task response callbacks (OK, Done, Postpone)
 */
class TaskResponseHandler
{
    /**
     * Handle task OK response (for notification-type tasks)
     */
    public static function handleOk(Nutgram $bot): void
    {
        try {
            $callbackData = $bot->callbackQuery()->data;
            $taskId = (int) str_replace('task_ok_', '', $callbackData);

            $user = auth()->user();
            if (!$user) {
                $bot->answerCallbackQuery('⚠️ Ошибка аутентификации', show_alert: true);
                return;
            }

            $task = Task::find($taskId);
            if (!$task) {
                $bot->answerCallbackQuery('⚠️ Задача не найдена', show_alert: true);
                return;
            }

            // Create or update response
            TaskResponse::updateOrCreate(
                [
                    'task_id' => $taskId,
                    'user_id' => $user->id,
                ],
                [
                    'status' => 'acknowledged',
                    'responded_at' => Carbon::now(),
                ]
            );

            $bot->answerCallbackQuery('✅ Принято');
            $bot->editMessageReplyMarkup(
                chat_id: $bot->chatId(),
                message_id: $bot->messageId(),
                reply_markup: null
            );

            Log::info("Task #{$taskId} acknowledged by user #{$user->id}");
        } catch (\Throwable $e) {
            Log::error('Error handling task OK: ' . $e->getMessage());
            $bot->answerCallbackQuery('⚠️ Произошла ошибка', show_alert: true);
        }
    }

    /**
     * Handle task done response
     */
    public static function handleDone(Nutgram $bot): void
    {
        try {
            $callbackData = $bot->callbackQuery()->data;
            $taskId = (int) str_replace('task_done_', '', $callbackData);

            $user = auth()->user();
            if (!$user) {
                $bot->answerCallbackQuery('⚠️ Ошибка аутентификации', show_alert: true);
                return;
            }

            $task = Task::find($taskId);
            if (!$task) {
                $bot->answerCallbackQuery('⚠️ Задача не найдена', show_alert: true);
                return;
            }

            // Create response
            TaskResponse::updateOrCreate(
                [
                    'task_id' => $taskId,
                    'user_id' => $user->id,
                ],
                [
                    'status' => 'completed',
                    'responded_at' => Carbon::now(),
                ]
            );

            $bot->answerCallbackQuery('✅ Задача отмечена как выполненная');
            $bot->editMessageReplyMarkup(
                chat_id: $bot->chatId(),
                message_id: $bot->messageId(),
                reply_markup: null
            );

            Log::info("Task #{$taskId} completed by user #{$user->id}");
        } catch (\Throwable $e) {
            Log::error('Error handling task done: ' . $e->getMessage());
            $bot->answerCallbackQuery('⚠️ Произошла ошибка', show_alert: true);
        }
    }

    /**
     * Handle task postpone response - start conversation for comment
     */
    public static function handlePostpone(Nutgram $bot): void
    {
        try {
            $callbackData = $bot->callbackQuery()->data;
            $taskId = (int) str_replace('task_postpone_', '', $callbackData);

            $user = auth()->user();
            if (!$user) {
                $bot->answerCallbackQuery('⚠️ Ошибка аутентификации', show_alert: true);
                return;
            }

            $task = Task::find($taskId);
            if (!$task) {
                $bot->answerCallbackQuery('⚠️ Задача не найдена', show_alert: true);
                return;
            }

            $bot->answerCallbackQuery();

            // Start postpone conversation
            \App\Bot\Conversations\Employee\PostponeTaskConversation::begin(
                $bot,
                taskId: $taskId,
                messageId: $bot->messageId()
            );
        } catch (\Throwable $e) {
            Log::error('Error handling task postpone: ' . $e->getMessage());
            $bot->answerCallbackQuery('⚠️ Произошла ошибка', show_alert: true);
        }
    }
}
