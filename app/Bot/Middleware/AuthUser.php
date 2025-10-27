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
                '⚠️ *Требуется авторизация*\n\n' .
                'Ваш аккаунт не зарегистрирован в системе.\n\n' .
                '🔐 Для входа используйте команду /start и поделитесь своим номером телефона.\n\n' .
                'ℹ️ Если ваш номер не найден, обратитесь к администратору для создания учетной записи.',
                parse_mode: 'Markdown'
            );
            return;
        }

        app()->instance('telegram_user', $user);
        auth()->setUser($user);

        return $next($bot);
    }
}
