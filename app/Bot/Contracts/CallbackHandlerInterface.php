<?php

declare(strict_types=1);

namespace App\Bot\Contracts;

use SergiX44\Nutgram\Nutgram;

/**
 * Interface for all callback handlers in the bot.
 * Follows Interface Segregation Principle by defining minimal required contract.
 */
interface CallbackHandlerInterface
{
    /**
     * Handle the callback query.
     *
     * @param Nutgram $bot The Nutgram instance
     * @param string $id The ID extracted from callback data
     * @return void
     */
    public function __invoke(Nutgram $bot, string $id): void;
}
