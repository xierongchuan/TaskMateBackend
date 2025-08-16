<?php

declare(strict_types=1);

namespace App\DTO\VCRM;

readonly class Department
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
        );
    }
}
