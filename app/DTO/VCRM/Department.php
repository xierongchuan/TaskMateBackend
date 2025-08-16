<?php

declare(strict_types=1);

namespace App\DTO\VCRM;

class Department
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {
    }
}
