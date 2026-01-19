<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Contracts\ValidationServiceInterface;

/**
 * Centralized validation service to eliminate code duplication.
 * Follows Single Responsibility Principle and DRY.
 */
class ValidationService implements ValidationServiceInterface
{
    /**
     * Validate expense amount input.
     */
    public function validateAmount(string $input): array
    {
        $normalized = $this->normalizeNumericInput($input);

        if ($normalized === '') {
            return [
                'valid' => false,
                'message' => 'Сумма не может быть пустой.'
            ];
        }

        if (!is_numeric($normalized)) {
            return [
                'valid' => false,
                'message' => 'Неверный формат суммы. Введите положительное число, например: 100000'
            ];
        }

        $amount = (float)$normalized;

        if ($amount <= 0) {
            return [
                'valid' => false,
                'message' => 'Сумма должна быть больше нуля.'
            ];
        }

        if ($amount > 9_999_999_999) {
            return [
                'valid' => false,
                'message' => 'Сумма слишком большая. Введите число менее 10 млрд.'
            ];
        }

        return [
            'valid' => true,
            'value' => $amount
        ];
    }

    /**
     * Validate comment/description input.
     */
    public function validateComment(string $input): array
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            return [
                'valid' => false,
                'message' => 'Комментарий не может быть пустым.'
            ];
        }

        if (mb_strlen($trimmed) < 3) {
            return [
                'valid' => false,
                'message' => 'Комментарий должен содержать минимум 3 символа.'
            ];
        }

        if (mb_strlen($trimmed) > 1000) {
            return [
                'valid' => false,
                'message' => 'Комментарий слишком длинный (максимум 1000 символов).'
            ];
        }

        return ['valid' => true];
    }

    /**
     * Validate that input is not empty.
     */
    public function validateNotEmpty(string $input): array
    {
        if (trim($input) === '') {
            return [
                'valid' => false,
                'message' => 'Поле не может быть пустым.'
            ];
        }

        return ['valid' => true];
    }

    /**
     * Normalize numeric input by removing spaces and replacing commas with dots.
     */
    private function normalizeNumericInput(string $input): string
    {
        return str_replace([',', ' '], ['.', ''], trim($input));
    }
}
