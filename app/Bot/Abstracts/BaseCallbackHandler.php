<?php

declare(strict_types=1);

namespace App\Bot\Abstracts;

use App\Bot\Contracts\CallbackHandlerInterface;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * Abstract base class for all callback handlers.
 * Implements common functionality following DRY principle.
 */
abstract class BaseCallbackHandler implements CallbackHandlerInterface
{
    /**
     * Handle the callback with error handling.
     */
    public function __invoke(Nutgram $bot, string $id): void
    {
        try {
            $this->validateUser($bot);
            $this->execute($bot, $id);
        } catch (Throwable $e) {
            $this->handleError($bot, $id, $e);
        }
    }

    /**
     * Execute the main callback logic.
     * Must be implemented by concrete classes.
     */
    abstract protected function execute(Nutgram $bot, string $id): void;

    /**
     * Validate that user is authenticated.
     */
    protected function validateUser(Nutgram $bot): User
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
    protected function handleError(Nutgram $bot, string $id, Throwable $e): void
    {
        Log::error('Callback handler error: ' . $e->getMessage(), $this->getLogContext($id, $e));

        $bot->answerCallbackQuery(
            text: $this->getErrorMessage($e),
            show_alert: true
        );
    }

    /**
     * Get error message for user.
     */
    protected function getErrorMessage(Throwable $e): string
    {
        return "Произошла ошибка при выполнении операции.";
    }

    /**
     * Get logging context.
     */
    protected function getLogContext(string $id, Throwable $e): array
    {
        return [
            'handler' => static::class,
            'request_id' => $id,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ];
    }
}
