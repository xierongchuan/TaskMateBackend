<?php

declare(strict_types=1);

namespace App\Bot\Conversations\Employee;

use App\Bot\Abstracts\BaseConversation;
use App\Models\Shift;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;

/**
 * Conversation for closing a shift with photo upload and task logging
 */
class CloseShiftConversation extends BaseConversation
{
    protected ?string $photoPath = null;
    protected ?Shift $shift = null;

    /**
     * Start: Check for open shift and request photo
     */
    public function start(Nutgram $bot): void
    {
        try {
            $user = $this->getAuthenticatedUser();

            // Find open shift
            $openShift = Shift::where('user_id', $user->id)
                ->where('status', 'open')
                ->whereNull('shift_end')
                ->first();

            if (!$openShift) {
                $bot->sendMessage('⚠️ У вас нет открытой смены.', reply_markup: static::employeeMenu());
                $this->end();
                return;
            }

            $this->shift = $openShift;

            $bot->sendMessage(
                '📸 Пожалуйста, загрузите фото экрана компьютера с текущим временем для закрытия смены.',
                reply_markup: \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
                    ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                        text: '⏭️ Пропустить фото',
                        callback_data: 'skip_photo'
                    ))
                    ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                        text: '❌ Отменить',
                        callback_data: 'cancel'
                    ))
            );

            $this->next('handlePhoto');
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'start');
        }
    }

    /**
     * Handle photo upload
     */
    public function handlePhoto(Nutgram $bot): void
    {
        try {
            // Handle skip button
            if ($bot->callbackQuery() && $bot->callbackQuery()->data === 'skip_photo') {
                $bot->answerCallbackQuery();
                $this->closeShift($bot);
                return;
            }

            // Handle cancel button
            if ($bot->callbackQuery() && $bot->callbackQuery()->data === 'cancel') {
                $bot->answerCallbackQuery();
                $bot->sendMessage('❌ Закрытие смены отменено.', reply_markup: static::employeeMenu());
                $this->end();
                return;
            }

            $photo = $bot->message()?->photo;

            if (!$photo || empty($photo)) {
                $bot->sendMessage(
                    '⚠️ Пожалуйста, отправьте фото.\n\n' .
                    'Или нажмите кнопку "Пропустить фото" или "Отменить".',
                    reply_markup: \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
                        ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                            text: '⏭️ Пропустить фото',
                            callback_data: 'skip_photo'
                        ))
                        ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                            text: '❌ Отменить',
                            callback_data: 'cancel'
                        ))
                );
                $this->next('handlePhoto');
                return;
            }

            // Get the largest photo (best quality)
            $largestPhoto = end($photo);
            $fileId = $largestPhoto->file_id;

            // Download photo from Telegram
            $file = $bot->getFile($fileId);
            $filePath = $file->file_path;

            // Download file content
            $fileContent = file_get_contents("https://api.telegram.org/file/bot{$bot->getConfig()->token}/{$filePath}");

            if ($fileContent === false) {
                throw new \RuntimeException('Failed to download photo');
            }

            // Save photo to storage
            $filename = 'shifts/' . uniqid('shift_close_photo_', true) . '.jpg';
            Storage::disk('public')->put($filename, $fileContent);

            $this->photoPath = $filename;

            $bot->sendMessage('✅ Фото получено. Закрываю смену...');

            $this->closeShift($bot);
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handlePhoto');
        }
    }

    /**
     * Close the shift and log incomplete tasks
     */
    private function closeShift(Nutgram $bot): void
    {
        try {
            $user = $this->getAuthenticatedUser();

            if (!$this->shift) {
                $bot->sendMessage('⚠️ Ошибка: смена не найдена.', reply_markup: static::employeeMenu());
                $this->end();
                return;
            }

            $now = Carbon::now();

            // Update shift
            $this->shift->shift_end = $now;
            $this->shift->status = 'closed';
            if ($this->photoPath) {
                $this->shift->closing_photo_path = $this->photoPath;
            }
            $this->shift->save();

            // Find incomplete tasks during this shift
            $incompleteTasks = Task::whereHas('assignments', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('appear_date')
                    ->orWhere('appear_date', '<=', Carbon::now());
            })
            ->whereDoesntHave('responses', function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->whereIn('status', ['completed', 'acknowledged']);
            })
            ->get();

            $message = '✅ Смена закрыта в ' . $now->format('H:i d.m.Y');

            if ($incompleteTasks->isNotEmpty()) {
                $message .= "\n\n⚠️ *Незавершённых задач: " . $incompleteTasks->count() . "*\n\n";

                // Log incomplete tasks
                foreach ($incompleteTasks as $task) {
                    $message .= "• {$task->title}";
                    if ($task->deadline) {
                        $message .= " (Дедлайн: " . $task->deadline->format('d.m H:i') . ")";
                    }
                    $message .= "\n";
                }

                // Notify managers about incomplete tasks
                $this->notifyManagersAboutIncompleteTasks($bot, $user, $incompleteTasks);
            } else {
                $message .= "\n\n✅ Все задачи выполнены!";
            }

            $bot->sendMessage($message, parse_mode: 'Markdown', reply_markup: static::employeeMenu());

            \Illuminate\Support\Facades\Log::info(
                "Shift closed by user #{$user->id}, incomplete tasks: " . $incompleteTasks->count()
            );

            $this->end();
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'closeShift');
        }
    }

    /**
     * Notify managers about incomplete tasks when shift closes
     */
    private function notifyManagersAboutIncompleteTasks(Nutgram $bot, User $user, $incompleteTasks): void
    {
        try {
            // Find managers for this dealership
            $managers = User::where('dealership_id', $user->dealership_id)
                ->whereIn('role', ['manager', 'owner'])
                ->whereNotNull('telegram_id')
                ->get();

            foreach ($managers as $manager) {
                $message = "⚠️ *Смена закрыта с незавершёнными задачами*\n\n";
                $message .= "👤 Сотрудник: {$user->full_name}\n";
                $message .= "🕐 Время закрытия: " . Carbon::now()->format('H:i d.m.Y') . "\n";
                $message .= "📋 Незавершённых задач: {$incompleteTasks->count()}\n\n";
                $message .= "*Список незавершённых задач:*\n";

                foreach ($incompleteTasks as $task) {
                    $message .= "• {$task->title}";
                    if ($task->deadline) {
                        $message .= " (⏰ {$task->deadline->format('d.m H:i')})";
                    }
                    $message .= "\n";
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
