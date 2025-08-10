<?php

declare(strict_types=1);

namespace App\Http\Services\VCRMServices;

class GetUserDataService
{
    private string $endpoint;
    private string $token;

    public function __construct()
    {
        $this->endpoint = config('services.vcrm.endpoint');
        $this->token = config('services.vcrm.token');
    }
}
