<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Типы ответов на задачу.
 *
 * - notification: достаточно подтвердить принятие задачи (уведомление)
 * - completion: требуется выполнить и отметить как завершённую
 * - completion_with_proof: требуется выполнить с загрузкой доказательств
 */
enum ResponseType: string
{
    case NOTIFICATION = 'notification';
    case COMPLETION = 'completion';
    case COMPLETION_WITH_PROOF = 'completion_with_proof';

    /** Читабельная метка (Ru) */
    public function label(): string
    {
        return match ($this) {
            self::NOTIFICATION => 'Уведомление',
            self::COMPLETION => 'На выполнение',
            self::COMPLETION_WITH_PROOF => 'С доказательством',
        };
    }

    /** Все значения для валидации */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }

    /** Безопасный парсинг из строки */
    public static function tryFromString(?string $v): ?self
    {
        return $v === null ? null : self::tryFrom($v);
    }
}
