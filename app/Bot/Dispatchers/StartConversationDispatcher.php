<?php

declare(strict_types=1);

namespace App\Bot\Dispatchers;

use SergiX44\Nutgram\Nutgram;
use App\Conversations\RequestExpenseConversation;
use App\Conversations\ApproveExpenseConversation;
use App\Conversations\IssueExpenseConversation;
use Psr\Log\LoggerInterface;
use App\Models\User;

class StartConversationDispatcher
{
    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function __invoke(Nutgram $bot)
    {
        $tgId = $bot->user()?->id ?? $bot->from()?->id ?? null;
        if ($tgId) {
            $user = User::where('telegram_id', $tgId)->with('role')->first();
        }

        $role = $user->role->name ?? $user->role_name ?? 'guest';

        // Маппинг ролей -> классы
        $map = [
            'guest' => \App\Bot\Conversations\Guest\StartConversation::class,
        ];

        $target = $map[$role] ?? null;

        if (!$target) {
            $bot->sendMessage('Ваша роль не поддерживает эту команду.');
            $this->logger->warning('expense.command.no_handler', ['tg_id' => $user->telegram_id, 'role' => $role]);
            return;
        }

        // Запускаем либо Conversation::begin, либо вызываем handler-метод
        if (is_subclass_of($target, \SergiX44\Nutgram\Conversations\Conversation::class)) {
            $target::begin($bot); // Nutgram: begin запускает Conversation
            return;
        }

        // Если это обычный handler класс с __invoke
        (new $target())($bot);
    }
}
