<?php

declare(strict_types=1);

namespace App\Bot\Contracts;

use SergiX44\Nutgram\Nutgram;

/**
 * Interface for all command handlers in the bot.
 */
interface CommandHandlerInterface
{
    /**
     * Handle the command.
     *
     * @param Nutgram $bot The Nutgram instance
     * @return void
     */
    public function handle(Nutgram $bot): void;
}
