<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Models\ExpenseApproval;
use App\Models\ExpenseRequest;
use App\Models\AuditLog;
use App\Traits\KeyboardTrait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Enums\ExpenseStatus;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use Throwable;
use App\Models\User;

class ConversationStateService
{
    public static function activateStatus(int $userId)
    {
        Cache::put("user_in_conversation_" . $userId, true, config('nutgram.conversationTtl'));
    }

    public static function deactivateStatus(int $userId)
    {
        Cache::delete("user_in_conversation_" . $userId);
    }

    public static function getStatus(int $userId): bool
    {
        return (bool) Cache::has("user_in_conversation_" . $userId);
    }
}
