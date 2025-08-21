<?php

declare(strict_types=1);

namespace App\Bot\Middleware;

use SergiX44\Nutgram\Nutgram;
use App\Models\User;

class AuthUser
{
    public function __invoke(Nutgram $bot, $next)
    {
        $tgId = $bot->user()?->id ?? $bot->from()?->id ?? null;
        if (!$tgId) {
            $bot->sendMessage('Не получается определить ваш Telegram ID.');
            return;
        }

        $user = User::where('telegram_id', $tgId)->first();
        if (!$user) {
            $bot->sendMessage(
                'Ваш аккаунт не зарегистрирован в системе — обратитесь к администратору.'
            );
            return;
        }

        app()->instance('telegram_user', $user);
        auth()->setUser($user);

        return $next($bot);
    }
}
