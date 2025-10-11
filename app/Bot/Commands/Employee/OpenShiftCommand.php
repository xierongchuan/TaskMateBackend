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
        // Check if user already has an open shift
        $openShift = Shift::where('user_id', $user->id)
            ->where('status', 'open')
            ->whereNull('shift_end')
            ->first();

        if ($openShift) {
            $bot->sendMessage(
                '⚠️ У вас уже есть открытая смена с ' .
                $openShift->shift_start->format('H:i d.m.Y')
            );
            return;
        }

        // Ask for photo of computer screen with current time
        $bot->sendMessage(
            '📸 Пожалуйста, загрузите фото экрана компьютера с текущим временем для открытия смены.',
            reply_markup: static::cancelKeyboard()
        );

        // Store state for next message handler
        $bot->setData('awaiting_shift_photo', true);
    }
}
