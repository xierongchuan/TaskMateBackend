<?php

declare(strict_types=1);

namespace App\Bot\Commands\Employee;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Models\User;
use SergiX44\Nutgram\Nutgram;

/**
 * Command for employees to close their shift
 */
class CloseShiftCommand extends BaseCommandHandler
{
    protected string $command = 'closeshift';
    protected ?string $description = 'Закрыть смену';

    protected function execute(Nutgram $bot, User $user): void
    {
        // Start the CloseShiftConversation
        \App\Bot\Conversations\Employee\CloseShiftConversation::begin($bot);
    }
}
