<?php

declare(strict_types=1);

namespace App\DTO\VCRM;

use App\DTO\VCRM\Company;
use App\DTO\VCRM\Department;
use App\DTO\VCRM\Post;

class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $login,
        public readonly string $role,
        public readonly string $status,
        public readonly string $full_name,
        public readonly string $phone_number,
        public readonly Company $company,
        public readonly Department $department,
        public readonly Post $post,
    ) {
    }
}
