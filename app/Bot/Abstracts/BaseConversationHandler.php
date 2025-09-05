<?php

declare(strict_types=1);

namespace App\Bot\Abstracts;

use App\Models\User;
use App\Traits\KeyboardTrait;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * Abstract base class for all conversation handlers.
 * Provides common functionality and error handling following SOLID principles.
 */
abstract class BaseConversationHandler extends Conversation
{
    use KeyboardTrait;

    /**
     * Get authenticated user from auth system.
     */
    protected function getAuthenticatedUser(): User
    {
        $user = auth()->user();

        if (!$user) {
            throw new \RuntimeException('User not authenticated');
        }

        return $user;
    }

    /**
     * Handle errors with consistent logging and user feedback.
     */
    protected function handleError(Nutgram $bot, Throwable $e, string $context = ''): void
    {
        $errorId = uniqid('conv_error_');

        Log::error("Conversation error [{$errorId}]", [
            'context' => $context,
            'conversation' => static::class,
            'user_id' => auth()->id(),
            'telegram_id' => $bot->user()?->id,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        $bot->sendMessage(
            'Произошла ошибка при обработке запроса. Попробуйте ещё раз или обратитесь к администратору.'
            . "\nID ошибки: {$errorId}"
        );

        $this->end();
    }
}
