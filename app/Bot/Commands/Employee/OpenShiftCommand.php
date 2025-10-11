<?php

declare(strict_types=1);

namespace App\Bot\Commands\Employee;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Models\Shift;
use App\Models\User;
use SergiX44\Nutgram\Nutgram;
use Carbon\Carbon;

/**
 * Command for employees to open their shift
 */
class OpenShiftCommand extends BaseCommandHandler
{
    protected string $command = 'openshift';
    protected ?string $description = 'Открыть смену';

    protected function execute(Nutgram $bot, User $user): void
    {
        // Start the OpenShiftConversation
        \App\Bot\Conversations\Employee\OpenShiftConversation::begin($bot);
    }
}
