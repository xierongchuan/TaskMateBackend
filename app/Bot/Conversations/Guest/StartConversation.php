<?php

declare(strict_types=1);

namespace App\Bot\Conversations\Guest;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use Illuminate\Support\Facades\DB;
use App\Models\ExpenseRequest;
use App\Models\ExpenseApproval;
use App\Models\User;

class StartConversation extends Conversation
{
    // Стартовый шаг
    protected ?string $step = 'askContact';

    public function askContact(Nutgram $bot)
    {
        $this->next('getContact');
    }

    public function getContact(Nutgram $bot)
    {
        $this->end();
    }

    public function closing(Nutgram $bot)
    {
        //
    }
}
