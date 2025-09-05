<?php

declare(strict_types=1);

namespace App\Bot\Contracts;

use SergiX44\Nutgram\Nutgram;

/**
 * Interface for conversation flows.
 */
interface ConversationInterface
{
    /**
     * Handle conversation step validation.
     *
     * @param mixed $input The input to validate
     * @return bool Whether input is valid
     */
    public function validateInput(mixed $input): bool;

    /**
     * Handle conversation cleanup.
     *
     * @param Nutgram $bot The Nutgram instance
     * @return void
     */
    public function closing(Nutgram $bot): void;
}
