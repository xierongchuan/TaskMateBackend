<?php

declare(strict_types=1);

namespace App\Bot\Middleware;

use SergiX44\Nutgram\Nutgram;

class ConversationGuard
{
    public function __invoke(Nutgram $bot, $next)
    {
        if ($bot->chat->conversation !== null) {
            $bot->sendMessage('Введите комментарий или завершите текущий диалог.');
            return;
        }
        return $next($bot);
    }
}
