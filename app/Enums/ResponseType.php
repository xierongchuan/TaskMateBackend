<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Типы ответов на задачу.
 *
 * - acknowledge: достаточно подтвердить принятие задачи
 * - complete: требуется выполнить и отметить как завершённую
 */
enum ResponseType: string
{
    case ACKNOWLEDGE = 'acknowledge';
    case COMPLETE = 'complete';

    /** Читабельная метка (Ru) */
    public function label(): string
    {
        return match ($this) {
            self::ACKNOWLEDGE => 'Подтверждение',
            self::COMPLETE => 'Выполнение',
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
