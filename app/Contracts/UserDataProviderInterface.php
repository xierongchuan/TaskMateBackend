<?php

declare(strict_types=1);

namespace App\Contracts;

interface UserDataProviderInterface
{
    /**
     * Fetch user data from external provider by id.
     *
     * @param int|string $id
     * @return array
     */
    public function fetchById(int|string $id): array;
}
