<?php

declare(strict_types=1);

namespace App\Bot\Middleware;

use App\Services\ConversationStateService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Conversations\Conversation;

class ConversationGuard
{
    public function __invoke(Nutgram $bot, $next)
    {
        if (ConversationStateService::getStatus($bot->userId())) {
            $bot->sendMessage('Вы уже в активном диалоге. Завершите его сначала.');
            return;
        }
        return $next($bot);
    }
}
