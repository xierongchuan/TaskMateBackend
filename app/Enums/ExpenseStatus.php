<?php

declare(strict_types=1);

namespace App\Enums;

enum ExpenseStatus: string
{
    case PENDING_DIRECTOR   = 'pending_director';
    case DIRECTOR_APPROVED  = 'director_approved';
    case DIRECTOR_DECLINED  = 'director_declined';
    case ISSUED            = 'issued';
    case CANCELLED         = 'cancelled';

    /** Читабельная метка (RU) */
    public function label(): string
    {
        return match ($this) {
            self::PENDING_DIRECTOR  => 'Ожидает руководителя',
            self::DIRECTOR_APPROVED => 'Одобрено руководителем',
            self::DIRECTOR_DECLINED => 'Отклонено руководителем',
            self::ISSUED           => 'Выдано (бухгалтер)',
            self::CANCELLED        => 'Отменено',
        };
    }

    /** все значения для миграции / проверок */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }

    /** безопасный парсинг из строки — вернёт null если неверно */
    public static function tryFromString(?string $v): ?self
    {
        return $v === null ? null : self::tryFrom($v);
    }
}
