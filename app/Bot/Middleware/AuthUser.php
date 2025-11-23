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
            Log::warning('AuthUser: Cannot determine Telegram ID', [
                'update_type' => $bot->update?->getType(),
                'chat_id' => $bot->chatId()
            ]);
            $bot->sendMessage('⚠️ Не удается определить ваш Telegram ID. Попробуйте снова.');
            return;
        }

        $user = User::where('telegram_id', $tgId)->first();
        if (!$user) {
            Log::info('AuthUser: User not found in system', [
                'telegram_id' => $tgId,
                'username' => $bot->user()?->username
            ]);
            $bot->sendMessage(
                "⚠️ *Требуется авторизация*\n\n" .
                "Ваш аккаунт не зарегистрирован в системе.\n\n" .
                "🔐 Для входа используйте команду /start и поделитесь своим номером телефона.\n\n" .
                "ℹ️ Если ваш номер не найден, обратитесь к администратору для создания учетной записи.",
                parse_mode: 'Markdown'
            );
            return;
        }

        // Make user available globally
        app()->instance('telegram_user', $user);
        auth()->setUser($user);

        Log::debug('AuthUser: User authenticated successfully', [
            'user_id' => $user->id,
            'telegram_id' => $tgId,
            'role' => $user->role
        ]);

        return $next($bot);
    }
}
