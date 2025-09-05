<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Models\ExpenseRequest;
use App\Models\User;
use SergiX44\Nutgram\Nutgram;

/**
 * Interface for notification services.
 */
interface NotificationServiceInterface
{
    /**
     * Notify user about expense request status.
     *
     * @param Nutgram $bot Bot instance
     * @param User $user User to notify
     * @param ExpenseRequest $request The expense request
     * @param string $status New status
     * @param string|null $comment Optional comment
     * @return bool Success status
     */
    public function notifyExpenseStatus(
        Nutgram $bot,
        User $user,
        ExpenseRequest $request,
        string $status,
        ?string $comment = null
    ): bool;

    /**
     * Send notification to director about new expense request.
     *
     * @param Nutgram $bot Bot instance
     * @param User $director Director to notify
     * @param ExpenseRequest $request The expense request
     * @return bool Success status
     */
    public function notifyDirectorNewRequest(
        Nutgram $bot,
        User $director,
        ExpenseRequest $request
    ): bool;

    /**
     * Notify accountant about approved expense request.
     *
     * @param Nutgram $bot Bot instance
     * @param User $accountant Accountant to notify
     * @param ExpenseRequest $request The expense request
     * @param string|null $directorComment Director's comment
     * @return bool Success status
     */
    public function notifyAccountantApproved(
        Nutgram $bot,
        User $accountant,
        ExpenseRequest $request,
        ?string $directorComment = null
    ): bool;

    /**
     * Update existing message (for callbacks).
     *
     * @param Nutgram $bot Bot instance
     * @param string $text New message text
     * @param int|null $messageId Message ID to update (if null, uses callback query message)
     * @return bool Success status
     */
    public function updateMessage(
        Nutgram $bot,
        string $text,
        ?int $messageId = null
    ): bool;
}
