<?php

declare(strict_types=1);

namespace App\Bot\Abstracts;

use App\Bot\Contracts\CommandHandlerInterface;
use App\Models\User;
use App\Traits\KeyboardTrait;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Handlers\Type\Command;
use Throwable;

/**
 * Abstract base class for all command handlers.
 * Provides common functionality and error handling.
 */
abstract class BaseCommandHandler extends Command implements CommandHandlerInterface
{
    use KeyboardTrait;

    /**
     * Handle the command with error handling.
     */
    public function handle(Nutgram $bot): void
    {
        try {
            $user = $this->validateUser();
            $this->execute($bot, $user);
        } catch (Throwable $e) {
            $this->handleError($bot, $e);
        }
    }

    /**
     * Execute the main command logic.
     * Must be implemented by concrete classes.
     */
    abstract protected function execute(Nutgram $bot, User $user): void;

    /**
     * Validate that user is authenticated.
     */
    protected function validateUser(): User
    {
        $user = auth()->user();

        if (!$user) {
            throw new \RuntimeException('User not authenticated');
        }

        return $user;
    }

    /**
     * Handle errors in a consistent way.
     */
    protected function handleError(Nutgram $bot, Throwable $e): void
    {
        Log::error('Command execution failed: ' . $e->getMessage(), $this->getLogContext($e));

        $bot->sendMessage($this->getErrorMessage($e));
    }

    /**
     * Get error message for user.
     */
    protected function getErrorMessage(Throwable $e): string
    {
        // Check for authentication errors
        if ($e->getMessage() === 'User not authenticated') {
            return '⚠️ Требуется авторизация. Пожалуйста, используйте /start для входа в систему.';
        }

        // Check for permission/authorization errors
        if (str_contains($e->getMessage(), 'permission') || str_contains($e->getMessage(), 'forbidden')) {
            return '⚠️ У вас нет прав для выполнения этой команды.';
        }

        // Check for validation errors
        if (str_contains($e->getMessage(), 'validation') || str_contains($e->getMessage(), 'invalid')) {
            return '⚠️ Некорректные данные. Пожалуйста, проверьте введенную информацию.';
        }

        // Generic error message
        return '⚠️ Произошла ошибка при выполнении команды. Попробуйте позже или обратитесь к администратору.';
    }

    /**
     * Get logging context.
     */
    protected function getLogContext(Throwable $e): array
    {
        $user = auth()->user();

        return [
            'handler' => static::class,
            'command' => $this->command,
            'user_id' => $user?->id,
            'user_role' => $user?->role,
            'telegram_id' => $user?->telegram_id,
            'error' => $e->getMessage(),
            'error_class' => get_class($e),
            'trace' => $e->getTraceAsString(),
        ];
    }
}
