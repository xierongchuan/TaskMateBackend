<?php

declare(strict_types=1);

namespace App\Enums;

enum Role: string
{
    case USER   = 'user';
    case ACCOUNTANT  = 'accountant';
    case DIRECTOR  = 'director';

    /** Читабельная метка (Ru) */
    public function label(): string
    {
        return match ($this) {
            self::USER  => 'Пользователь',
            self::ACCOUNTANT => 'Бухгалтер',
            self::DIRECTOR => 'Директор',
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
