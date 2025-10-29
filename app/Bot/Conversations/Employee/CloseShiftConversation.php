<?php

declare(strict_types=1);

namespace App\Bot\Conversations\Employee;

use App\Bot\Abstracts\BaseConversation;
use App\Models\Shift;
use App\Models\Task;
use App\Models\User;
use App\Services\ShiftService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
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
            $shiftService = app(ShiftService::class);

            // Validate user belongs to a dealership
            if (!$shiftService->validateUserDealership($user)) {
                $bot->sendMessage(
                    '⚠️ Вы не привязаны к дилерскому центру. Обратитесь к администратору.',
                    reply_markup: static::employeeMenu()
                );
                $this->end();
                return;
            }

            // Find open shift using ShiftService
            $openShift = $shiftService->getUserOpenShift($user);

            if (!$openShift) {
                $bot->sendMessage('⚠️ У вас нет открытой смены.', reply_markup: static::employeeMenu());
                $this->end();
                return;
            }

            $this->shift = $openShift;

            // Show shift info before requesting photo
            $message = "🕐 Текущая смена открыта в " . $openShift->shift_start->format('H:i d.m.Y') . "\n\n";
            if ($openShift->status === 'late') {
                $message .= "⚠️ Смена открыта с опозданием на {$openShift->late_minutes} минут.\n\n";
            }
            $message .= "📸 Пожалуйста, загрузите фото экрана компьютера с текущим временем для закрытия смены.";

            $bot->sendMessage(
                $message,
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

            if (!$file || !$file->file_path) {
                throw new \RuntimeException('Failed to get file info from Telegram');
            }

            // Download file to temporary location
            $tempPath = sys_get_temp_dir() . '/shift_close_photo_' . uniqid() . '.jpg';
            $bot->downloadFile($file, $tempPath);

            if (!file_exists($tempPath)) {
                throw new \RuntimeException('Failed to download photo from Telegram');
            }

            // Store as UploadedFile for compatibility with ShiftService
            $this->photoPath = $tempPath;

            $bot->sendMessage('✅ Фото получено. Закрываю смену...');

            $this->closeShift($bot);
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handlePhoto');
        }
    }

    /**
     * Close the shift using ShiftService
     */
    private function closeShift(Nutgram $bot): void
    {
        try {
            $user = $this->getAuthenticatedUser();
            $shiftService = app(ShiftService::class);

            if (!$this->shift) {
                $bot->sendMessage('⚠️ Ошибка: смена не найдена.', reply_markup: static::employeeMenu());
                $this->end();
                return;
            }

            $now = Carbon::now();
            $closingPhoto = null;

            // Create UploadedFile from the temporary photo path if provided
            if ($this->photoPath && file_exists($this->photoPath)) {
                $closingPhoto = new UploadedFile(
                    $this->photoPath,
                    'shift_closing_photo.jpg',
                    'image/jpeg',
                    null,
                    true
                );
            }

            // Use ShiftService to close the shift
            $updatedShift = $shiftService->closeShift($user, $closingPhoto);

            // Clean up temporary file
            if ($this->photoPath && file_exists($this->photoPath)) {
                unlink($this->photoPath);
            }

            // Calculate shift duration
            $duration = $updatedShift->shift_start->diffInMinutes($updatedShift->shift_end);
            $hours = floor($duration / 60);
            $minutes = $duration % 60;

            $message = '✅ Смена закрыта в ' . $now->format('H:i d.m.Y') . "\n\n";
            $message .= "🕐 Продолжительность: {$hours}ч {$minutes}м\n";
            $message .= "📊 Статус: " . ($updatedShift->status === 'late' ? 'Опоздание' : 'Нормально') . "\n";

            // Find incomplete tasks using dealership context
            $incompleteTasks = Task::whereHas('assignments', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->orWhere('task_type', 'group') // Include group tasks
                ->where('dealership_id', $this->shift->dealership_id)
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
                "Shift closed by user #{$user->id} in dealership #{$this->shift->dealership_id}, " .
                "duration: {$duration} minutes, incomplete tasks: " . $incompleteTasks->count()
            );

            $this->end();
        } catch (\Throwable $e) {
            // Clean up temporary file on error
            if ($this->photoPath && file_exists($this->photoPath)) {
                unlink($this->photoPath);
            }
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
