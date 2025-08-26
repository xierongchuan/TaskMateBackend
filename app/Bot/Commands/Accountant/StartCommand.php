<?php

declare(strict_types=1);

namespace App\Bot\Commands\Accountant;

use App\Enums\Role;
use App\Traits\KeyboardTrait;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use Psr\Log\LoggerInterface;
use App\Models\User;

class StartCommand extends Command
{
    protected string $command = 'start';

    protected ?string $description = '';

    public function handle(Nutgram $bot): void
    {
        try {
            $user = auth()->user();

            $role = Role::tryFromString($user->role)->label();

            $bot->sendMessage(
                'Добро пожаловать ' . $role . ' ' . $user->full_name . '!',
                reply_markup: KeyboardTrait::removeKeyboard()
            );
        } catch (\Throwable $e) {
            Log::error('accountant.start.command.failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $bot->sendMessage('Произошла ошибка при запуске. Попробуйте позже.');
        }
    }
}
