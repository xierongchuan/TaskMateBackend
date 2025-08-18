<?php

declare(strict_types=1);

namespace App\DTO\VCRM;

use App\DTO\VCRM\Company;
use App\DTO\VCRM\Department;
use App\DTO\VCRM\Post;
use InvalidArgumentException;

readonly class User
{
    public function __construct(
        public int $id,
        public string $login,
        public string $role,
        public string $status,
        public string $fullName,
        public string $phoneNumber,
        public ?Company $company,
        public ?Department $department,
        public ?Post $post,
    ) {
    }

    public static function fromArray(array $data): self
    {
        // проверка обязательных полей
        foreach (['id', 'login', 'role', 'status', 'full_name', 'phone_number'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }

        return new self(
            id: $data['id'],
            login: $data['login'],
            role: $data['role'],
            status: $data['status'],
            fullName: $data['full_name'],
            phoneNumber: $data['phone_number'],
            company: isset($data['company'])
                ? Company::fromArray($data['company'])
                : null,
            department: isset($data['department'])
                ? Department::fromArray($data['department'])
                : null,
            post: isset($data['post'])
                ? Post::fromArray($data['post'])
                : null,
        );
    }
}
