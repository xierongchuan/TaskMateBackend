<?php

declare(strict_types=1);

namespace App\Bot\Conversations\Employee;

use App\Bot\Abstracts\BaseConversation;
use App\Models\Task;
use App\Models\TaskResponse;
use Carbon\Carbon;
use SergiX44\Nutgram\Nutgram;

/**
 * Conversation for postponing a task with a comment
 */
class PostponeTaskConversation extends BaseConversation
{
    protected int $taskId;
    protected ?int $originalMessageId = null;

    /**
     * Begin conversation with task ID
     */
    public static function begin(Nutgram $bot, int $taskId, ?int $messageId = null): void
    {
        $conversation = new static();
        $conversation->taskId = $taskId;
        $conversation->originalMessageId = $messageId;
        $conversation->start($bot);
    }

    /**
     * Start: Ask for postpone reason
     */
    public function start(Nutgram $bot): void
    {
        try {
            $task = Task::find($this->taskId);

            if (!$task) {
                $bot->sendMessage('⚠️ Задача не найдена.');
                $this->end();
                return;
            }

            $bot->sendMessage(
                "⏭️ Перенос задачи: *{$task->title}*\n\n" .
                "💬 Укажите причину переноса на завтра:",
                parse_mode: 'Markdown',
                reply_markup: \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
                    ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                        text: '❌ Отменить',
                        callback_data: 'cancel_postpone'
                    ))
            );

            $this->next('handleComment');
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'start');
        }
    }

    /**
     * Handle postpone comment
     */
    public function handleComment(Nutgram $bot): void
    {
        try {
            // Handle cancel button
            if ($bot->callbackQuery() && $bot->callbackQuery()->data === 'cancel_postpone') {
                $bot->answerCallbackQuery();
                $bot->sendMessage('❌ Перенос задачи отменен.', reply_markup: static::employeeMenu());
                $this->end();
                return;
            }

            $comment = $bot->message()?->text;

            if (!$comment || trim($comment) === '') {
                $bot->sendMessage('⚠️ Пожалуйста, укажите причину переноса.');
                $this->next('handleComment');
                return;
            }

            $user = $this->getAuthenticatedUser();
            $task = Task::find($this->taskId);

            if (!$task) {
                $bot->sendMessage('⚠️ Задача не найдена.');
                $this->end();
                return;
            }

            // Create or update response
            TaskResponse::updateOrCreate(
                [
                    'task_id' => $this->taskId,
                    'user_id' => $user->id,
                ],
                [
                    'status' => 'postponed',
                    'comment' => trim($comment),
                    'responded_at' => Carbon::now(),
                ]
            );

            // Increment postpone count
            $task->increment('postpone_count');

            // Remove keyboard from original task message
            if ($this->originalMessageId) {
                try {
                    $bot->editMessageReplyMarkup(
                        chat_id: $bot->chatId(),
                        message_id: $this->originalMessageId,
                        reply_markup: null
                    );
                } catch (\Throwable $e) {
                    // Ignore if message can't be edited
                }
            }

            $bot->sendMessage(
                "✅ Задача перенесена на завтра.\n\n" .
                "💬 Причина: " . trim($comment),
                reply_markup: static::employeeMenu()
            );

            // Notify manager about postponement
            $this->notifyManagerAboutPostponement($bot, $task, $user, trim($comment));

            \Illuminate\Support\Facades\Log::info("Task #{$this->taskId} postponed by user #{$user->id}");

            $this->end();
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handleComment');
        }
    }

    /**
     * Notify manager about task postponement
     */
    private function notifyManagerAboutPostponement(Nutgram $bot, Task $task, $user, string $comment): void
    {
        try {
            // Find managers for this dealership
            $managers = \App\Models\User::where('dealership_id', $user->dealership_id)
                ->whereIn('role', ['manager', 'owner'])
                ->whereNotNull('telegram_id')
                ->get();

            foreach ($managers as $manager) {
                $message = "⚠️ *Задача перенесена*\n\n";
                $message .= "👤 Сотрудник: {$user->full_name}\n";
                $message .= "📋 Задача: {$task->title}\n";
                $message .= "💬 Причина: {$comment}\n";
                $message .= "🔢 Количество переносов: {$task->postpone_count}\n";

                if ($task->postpone_count > 1) {
                    $message .= "\n⚠️ *Внимание: задача переносилась более 1 раза!*";
                }

                try {
                    $bot->sendMessage(
                        text: $message,
                        chat_id: $manager->telegram_id,
                        parse_mode: 'Markdown'
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning(
                        "Failed to notify manager #{$manager->id}: " . $e->getMessage()
                    );
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error notifying managers: ' . $e->getMessage());
        }
    }

    /**
     * Get default keyboard
     */
    protected function getDefaultKeyboard()
    {
        return static::employeeMenu();
    }
}
