<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Models\ExpenseRequest;
use App\Models\User;
use SergiX44\Nutgram\Nutgram;

/**
 * Interface for expense approval operations.
 */
interface ExpenseApprovalServiceInterface
{
    /**
     * Approve expense request.
     *
     * @param Nutgram $bot Bot instance
     * @param int $requestId Expense request ID
     * @param User $director Director approving the request
     * @param string|null $comment Optional comment
     * @return array{success: bool, message?: string, request?: ExpenseRequest}
     */
    public function approveRequest(
        Nutgram $bot,
        int $requestId,
        User $director,
        ?string $comment = null
    ): array;

    /**
     * Decline expense request.
     *
     * @param Nutgram $bot Bot instance
     * @param int $requestId Expense request ID
     * @param User $director Director declining the request
     * @param string|null $reason Optional reason
     * @return array{success: bool, message?: string, request?: ExpenseRequest}
     */
    public function declineRequest(
        Nutgram $bot,
        int $requestId,
        User $director,
        ?string $reason = null
    ): array;

    /**
     * Mark expense as issued.
     *
     * @param Nutgram $bot Bot instance
     * @param int $requestId Expense request ID
     * @param User $accountant Accountant issuing the expense
     * @return array{success: bool, message?: string, request?: ExpenseRequest}
     */
    public function issueExpense(
        Nutgram $bot,
        int $requestId,
        User $accountant
    ): array;
}
