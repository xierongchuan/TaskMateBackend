<?php

declare(strict_types=1);

namespace App\Http\Services\VCRMServices;

use App\Contracts\UserDataProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class UserDataService implements UserDataProviderInterface
{
    private string $apiUrl;
    private string $token;

    public function __construct()
    {
        $this->apiUrl = rtrim(config('services.vcrm.api_url'));
        $this->token = config('services.vcrm.api_token');
    }

    /**
     * Fetch user data from VCRM by id.
     *
     * @param int|string $userId
     * @return object
     * @throws RequestException
     */
    public function fetchById(int|string $userId): object
    {
        if (empty($this->token)) {
            Log::error('VCRM token is missing for fetchById', ['userId' => $userId]);
        }

        $url = "{$this->apiUrl}/user/{$userId}";

        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->timeout(10)
                ->get($url);

            $response->throw();

            $data = (object)((object)$response->json())->data;

            return $data ?? [];
        } catch (ConnectionException $e) {
            Log::error('VCRM connection failed', [
                'url' => $url,
                'userId' => $userId,
                'message' => $e->getMessage(),
            ]);

            return [];
        } catch (RequestException $e) {
            // здесь можно обработать 4xx/5xx отдельно
            Log::error('VCRM request error', [
                'url' => $url,
                'status' => $e->response?->status(),
                'body' => $e->response?->body(), // осторожно с логированием чувствительных данных
            ]);

            throw $e;
        }
    }
}
