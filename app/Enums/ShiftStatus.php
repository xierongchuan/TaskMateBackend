<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Статусы смен.
 */
enum ShiftStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';

    /** Читабельная метка (Ru) */
    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Открыта',
            self::CLOSED => 'Закрыта',
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
