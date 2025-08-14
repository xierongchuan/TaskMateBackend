<?php

declare(strict_types=1);

namespace App\Http\Services\VCRMServices;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UserDataService
{
    private string $apiUrl;
    private ?string $defaultToken;

    public function __construct(?string $apiUrl = null, ?string $defaultToken = null)
    {
        $this->apiUrl = rtrim($apiUrl ?? config('services.vcrm.api_url', ''), '/');
        $this->defaultToken = $defaultToken ?? config('services.vcrm.api_token');
    }

    /**
     * @param int|string $userId
     * @param string|null $sessionToken
     * @return object
     * @throws RequestException
     */
    public function fetchById(int|string $userId, ?string $sessionToken = null): object
    {
        $token = $sessionToken ?? $this->defaultToken;
        if (empty($token)) {
            Log::error('VCRM token missing', ['userId' => $userId]);
            throw new RuntimeException('VCRM token is required');
        }

        $url = "{$this->apiUrl}/user/{$userId}";

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(10)
                ->get($url);

            $response->throw();

            $payload = data_get($response->json(), 'data', []);

            return (object) ($payload ?? []);
        } catch (ConnectionException $e) {
            Log::error('VCRM connection failed', ['url' => $url, 'msg' => $e->getMessage()]);
            return (object) [];
        } catch (RequestException $e) {
            Log::error('VCRM request error', ['url' => $url, 'status' => $e->response?->status()]);
            throw $e;
        }
    }
}
