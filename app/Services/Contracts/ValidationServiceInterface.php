<?php

declare(strict_types=1);

namespace App\Services\Contracts;

/**
 * Interface for validation services.
 */
interface ValidationServiceInterface
{
    /**
     * Validate expense amount.
     *
     * @param string $input User input to validate
     * @return array{valid: bool, value?: float, message?: string}
     */
    public function validateAmount(string $input): array;

    /**
     * Validate comment/description text.
     *
     * @param string $input User input to validate
     * @return array{valid: bool, message?: string}
     */
    public function validateComment(string $input): array;

    /**
     * Validate that input is not empty.
     *
     * @param string $input User input to validate
     * @return array{valid: bool, message?: string}
     */
    public function validateNotEmpty(string $input): array;
}
