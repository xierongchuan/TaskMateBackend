<?php

namespace App\Dto;

use Carbon\Carbon;

class TokenResponse
{
    public function __construct(
        public string $token,
        public Carbon $expiresAt
    ) {}

    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'expires_at' => $this->expiresAt->toIso8601String()
        ];
    }
}
