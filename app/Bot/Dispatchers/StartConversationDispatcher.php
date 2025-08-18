<?php

declare(strict_types=1);

namespace App\Bot\Dispatchers;

use SergiX44\Nutgram\Nutgram;
use App\Conversations\RequestExpenseConversation;
use App\Conversations\ApproveExpenseConversation;
use App\Conversations\IssueExpenseConversation;
use Psr\Log\LoggerInterface;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class StartConversationDispatcher
{
    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function __invoke(Nutgram $bot)
    {
        $telegramUserId = $bot->user()->id ?? null;
        if ($telegramUserId) {
            $user = User::where('telegram_id', $telegramUserId)->first();
        }

        $role = $user->role ?? 'guest';

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

        $target::begin($bot); // Nutgram: begin запускает Conversation
    }
}
