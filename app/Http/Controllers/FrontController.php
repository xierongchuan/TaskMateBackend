<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use SergiX44\Nutgram\Nutgram;
use App\Bot\Middleware\ConversationAuthMiddleware;
use Log;

class FrontController extends Controller
{
    /**
     * Handle the telegram webhook request.
     */
    public function webhook(Nutgram $bot)
    {
        $bot->run();
    }
}
