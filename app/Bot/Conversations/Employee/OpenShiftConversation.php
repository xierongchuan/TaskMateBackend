<?php

declare(strict_types=1);

namespace App\Bot\Conversations\Employee;

use App\Bot\Abstracts\BaseConversation;
use App\Models\Shift;
use App\Models\User;
use App\Models\ShiftReplacement;
use App\Services\ShiftService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;

/**
 * Conversation for opening a shift with photo upload and optional replacement
 */
class OpenShiftConversation extends BaseConversation
{
    protected ?string $photoPath = null;
    protected ?bool $isReplacement = null;
    protected ?int $replacedUserId = null;
    protected ?string $replacementReason = null;

    /**
     * Start: Ask for photo of computer screen with current time
     */
    public function start(Nutgram $bot): void
    {
        try {
            $user = $this->getAuthenticatedUser();
            $shiftService = app(ShiftService::class);

            // Validate user belongs to a dealership
            if (!$shiftService->validateUserDealership($user)) {
                $bot->sendMessage(
                    '⚠️ Вы не привязаны к дилерскому центру. Обратитесь к администратору.'
                );
                $this->end();
                return;
            }

            // Check if user already has an open shift
            $openShift = $shiftService->getUserOpenShift($user);

            if ($openShift) {
                $bot->sendMessage(
                    '⚠️ У вас уже есть открытая смена с ' .
                    $openShift->shift_start->format('H:i d.m.Y')
                );
                $this->end();
                return;
            }

            $bot->sendMessage(
                '📸 Пожалуйста, загрузите фото экрана компьютера с текущим временем для открытия смены.',
                reply_markup: static::inlineConfirmDecline('skip_photo', 'cancel')
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
            // Handle cancel button
            if ($bot->callbackQuery() && $bot->callbackQuery()->data === 'cancel') {
                $bot->answerCallbackQuery();
                $bot->sendMessage('❌ Открытие смены отменено.', reply_markup: static::employeeMenu());
                $this->end();
                return;
            }

            $photo = $bot->message()?->photo;

            if (!$photo || empty($photo)) {
                $bot->sendMessage(
                    '⚠️ Пожалуйста, отправьте фото.\n\n' .
                    'Или нажмите кнопку "Отменить" для выхода.',
                    reply_markup: static::inlineConfirmDecline('skip_photo', 'cancel')
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
            $tempPath = sys_get_temp_dir() . '/shift_photo_' . uniqid() . '.jpg';
            $bot->downloadFile($file, $tempPath);

            if (!file_exists($tempPath)) {
                throw new \RuntimeException('Failed to download photo from Telegram');
            }

            // Store as UploadedFile for compatibility with ShiftService
            $this->photoPath = $tempPath;

            // Ask if replacing another employee
            $bot->sendMessage(
                '✅ Фото получено.\n\n' .
                '❓ Вы заменяете другого сотрудника?',
                reply_markup: static::yesNoKeyboard('Да', 'Нет')
            );

            $this->next('handleReplacementQuestion');
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handlePhoto');
        }
    }

    /**
     * Handle replacement question
     */
    public function handleReplacementQuestion(Nutgram $bot): void
    {
        try {
            // Handle callback query if user somehow triggered one (shouldn't happen with ReplyKeyboard)
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery();
                $bot->sendMessage(
                    '⚠️ Пожалуйста, используйте кнопки ниже для ответа.',
                    reply_markup: static::yesNoKeyboard('Да', 'Нет')
                );
                $this->next('handleReplacementQuestion');
                return;
            }

            $answer = $bot->message()?->text;

            if (!$answer) {
                $bot->sendMessage(
                    '⚠️ Пожалуйста, выберите "Да" или "Нет"',
                    reply_markup: static::yesNoKeyboard('Да', 'Нет')
                );
                $this->next('handleReplacementQuestion');
                return;
            }

            if ($answer === 'Да') {
                $this->isReplacement = true;

                // Get list of employees from same dealership
                $user = $this->getAuthenticatedUser();
                $employees = User::where('dealership_id', $user->dealership_id)
                    ->where('role', 'employee')
                    ->where('id', '!=', $user->id)
                    ->get();

                if ($employees->isEmpty()) {
                    $bot->sendMessage(
                        '⚠️ Не найдено других сотрудников в вашем салоне.',
                        reply_markup: static::removeKeyboard()
                    );
                    $this->createShift($bot);
                    return;
                }

                // Create inline keyboard with employee list
                $buttons = [];
                foreach ($employees as $employee) {
                    $buttons[] = [
                        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                            text: $employee->full_name,
                            callback_data: 'employee_' . $employee->id
                        )
                    ];
                }

                $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make();
                foreach ($buttons as $row) {
                    $keyboard->addRow(...$row);
                }

                // First remove the reply keyboard, then show inline keyboard
                $bot->sendMessage('✅ Понятно', reply_markup: static::removeKeyboard());
                $bot->sendMessage(
                    '👤 Выберите сотрудника, которого вы заменяете:',
                    reply_markup: $keyboard
                );

                $this->next('handleEmployeeSelection');
            } elseif ($answer === 'Нет') {
                $this->isReplacement = false;
                // Remove the reply keyboard before creating shift
                $bot->sendMessage('✅ Понятно, открываем смену...', reply_markup: static::removeKeyboard());
                $this->createShift($bot);
            } else {
                $bot->sendMessage(
                    '⚠️ Пожалуйста, выберите "Да" или "Нет"',
                    reply_markup: static::yesNoKeyboard('Да', 'Нет')
                );
                $this->next('handleReplacementQuestion');
            }
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handleReplacementQuestion');
        }
    }

    /**
     * Handle employee selection
     */
    public function handleEmployeeSelection(Nutgram $bot): void
    {
        try {
            $callbackData = $bot->callbackQuery()?->data;

            if (!$callbackData || !str_starts_with($callbackData, 'employee_')) {
                $bot->sendMessage('⚠️ Ошибка выбора сотрудника. Попробуйте снова.');
                $this->end();
                return;
            }

            $this->replacedUserId = (int) str_replace('employee_', '', $callbackData);

            $bot->answerCallbackQuery();
            $bot->sendMessage(
                '✍️ Укажите причину замещения:',
                reply_markup: static::removeKeyboard()
            );

            $this->next('handleReplacementReason');
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handleEmployeeSelection');
        }
    }

    /**
     * Handle replacement reason
     */
    public function handleReplacementReason(Nutgram $bot): void
    {
        try {
            $reason = $bot->message()?->text;

            if (!$reason || trim($reason) === '') {
                $bot->sendMessage('⚠️ Пожалуйста, укажите причину замещения.');
                $this->next('handleReplacementReason');
                return;
            }

            $this->replacementReason = trim($reason);

            $this->createShift($bot);
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handleReplacementReason');
        }
    }

    /**
     * Create shift record using ShiftService
     */
    private function createShift(Nutgram $bot): void
    {
        try {
            $user = $this->getAuthenticatedUser();
            $shiftService = app(ShiftService::class);

            // Create UploadedFile from the temporary photo path
            if (!$this->photoPath || !file_exists($this->photoPath)) {
                throw new \RuntimeException('Photo file not found');
            }

            $uploadedFile = new UploadedFile(
                $this->photoPath,
                'shift_opening_photo.jpg',
                'image/jpeg',
                null,
                true
            );

            // Get replacement user if needed
            $replacingUser = null;
            if ($this->isReplacement && $this->replacedUserId) {
                $replacingUser = User::findOrFail($this->replacedUserId);

                // Validate replacement user belongs to the same dealership
                if (!$shiftService->validateUserDealership($replacingUser, $user->dealership_id)) {
                    $bot->sendMessage(
                        '⚠️ Выбранный сотрудник не принадлежит вашему дилерскому центру.'
                    );
                    $this->end();
                    return;
                }
            }

            // Use ShiftService to create the shift
            $shift = $shiftService->openShift(
                $user,
                $uploadedFile,
                $replacingUser,
                $this->replacementReason
            );

            // Clean up temporary file
            if (file_exists($this->photoPath)) {
                unlink($this->photoPath);
            }

            // Send welcome message and tasks
            $now = Carbon::now();
            $message = "✅ Смена открыта в " . $now->format('H:i d.m.Y') . "\n\n";
            $message .= "👋 Приветствие!\n\n";

            if ($this->isReplacement) {
                $message .= "📝 Вы заменяете: {$replacingUser->full_name}\n";
                $message .= "💬 Причина: {$this->replacementReason}\n\n";
            }

            // Add shift status information
            if ($shift->status === 'late') {
                $message .= "⚠️ Смена открыта с опозданием на {$shift->late_minutes} минут.\n\n";
            }

            $message .= "🕐 Планируемое время: " . $shift->scheduled_start->format('H:i') . " - " .
                       $shift->scheduled_end->format('H:i') . "\n\n";

            $bot->sendMessage($message, reply_markup: static::employeeMenu());

            // Send pending tasks
            $this->sendPendingTasks($bot, $user);

            $this->end();
        } catch (\Throwable $e) {
            // Clean up temporary file on error
            if ($this->photoPath && file_exists($this->photoPath)) {
                unlink($this->photoPath);
            }
            $this->handleError($bot, $e, 'createShift');
        }
    }

    
    /**
     * Send pending tasks to the employee
     */
    private function sendPendingTasks(Nutgram $bot, User $user): void
    {
        try {
            $tasks = \App\Models\Task::whereHas('assignments', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('appear_date')
                    ->orWhere('appear_date', '<=', Carbon::now());
            })
            ->whereDoesntHave('responses', function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->where('status', 'completed');
            })
            ->get();

            if ($tasks->isEmpty()) {
                $bot->sendMessage('✅ У вас нет активных задач.');
                return;
            }

            $bot->sendMessage("📋 У вас {$tasks->count()} активных задач:");

            foreach ($tasks as $task) {
                $this->sendTaskNotification($bot, $task, $user);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error sending tasks: ' . $e->getMessage());
        }
    }

    /**
     * Send task notification
     */
    private function sendTaskNotification(Nutgram $bot, \App\Models\Task $task, User $user): void
    {
        $message = "📌 *{$task->title}*\n\n";

        if ($task->description) {
            $message .= "{$task->description}\n\n";
        }

        if ($task->comment) {
            $message .= "💬 Комментарий: {$task->comment}\n\n";
        }

        if ($task->deadline) {
            $message .= "⏰ Дедлайн: " . $task->deadline_for_bot . "\n";
        }

        // Create response keyboard based on response_type
        $keyboard = match ($task->response_type) {
            'notification' => \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
                ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    text: '✅ OK',
                    callback_data: 'task_ok_' . $task->id
                )),
            'execution' => \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
                ->addRow(
                    \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                        text: '✅ Выполнено',
                        callback_data: 'task_done_' . $task->id
                    ),
                    \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                        text: '⏭️ Перенести',
                        callback_data: 'task_postpone_' . $task->id
                    )
                ),
            default => null,
        };

        $bot->sendMessage($message, parse_mode: 'Markdown', reply_markup: $keyboard);
    }

    /**
     * Get default keyboard
     */
    protected function getDefaultKeyboard()
    {
        return static::employeeMenu();
    }
}
