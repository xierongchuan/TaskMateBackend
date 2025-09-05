<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Models\User;
use SergiX44\Nutgram\Nutgram;

/**
 * Interface for expense-related operations.
 * Following Interface Segregation Principle.
 */
interface ExpenseServiceInterface
{
    /**
     * Create a new expense request.
     *
     * @param Nutgram $bot Bot instance
     * @param User $requester User making the request
     * @param string $description Description of the expense
     * @param float $amount Amount requested
     * @param string $currency Currency code
     * @return int|null Request ID if successful, null otherwise
     */
    public function createRequest(
        Nutgram $bot,
        User $requester,
        string $description,
        float $amount,
        string $currency = 'UZS'
    ): ?int;
}
