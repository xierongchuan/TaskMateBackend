<?php

declare(strict_types=1);

namespace App\Http\Services\VCRMServices;

use App\Contracts\UserDataProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;

class UserDataService implements UserDataProviderInterface
{
    private string $endpoint;
    private string $token;

    public function __construct()
    {
        $this->endpoint = config('services.vcrm.api_url');
        $this->token = config('services.vcrm.api_token');
    }

    /**
     * Fetch user data from VCRM by id.
     *
     * @param int|string $userId
     * @return array
     * @throws RequestException
     */
    public function fetchById(int|string $userId): array
    {
        $response = Http::withToken($this->token)
            ->acceptJson()
            ->timeout(10)
            ->get("{$this->endpoint}/user/{$userId}");

        $response->throw();

        return $response->json() ?? [];
    }
}
