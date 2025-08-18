<?php

declare(strict_types=1);

namespace App\Http\Services\VCRM;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use App\DTO\VCRM\User as VCRMUser;

class UserService
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
     * @throws ConnectionException|RequestException
     */
    public function fetchById(int|string $userId, ?string $sessionToken = null): object
    {
        $token = $sessionToken ?? $this->defaultToken;
        if (empty($token)) {
            Log::error('VCRM token missing');
            throw new RuntimeException('VCRM token is required');
        }

        $url = "{$this->apiUrl}/users/{$userId}";

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(10)
                ->get($url);

            $response->throw();

            $payload = (array) data_get($response->json(), 'data', []);

            return VCRMUser::fromArray($payload);
        } catch (ConnectionException $e) {
            Log::error('VCRM connection failed', ['url' => $url, 'msg' => $e->getMessage()]);
            return (object) [];
        } catch (RequestException $e) {
            Log::error('VCRM request error', ['url' => $url, 'status' => $e->response?->status()]);
            throw $e;
        }
    }

    /**
     * Получить пользователя по номеру телефона.
     *
     * @param string $phoneNumber
     * @param string|null $sessionToken
     * @return object
     * @throws ConnectionException|RequestException
     */
    public function fetchByPhone(string $phoneNumber, ?string $sessionToken = null): bool|VCRMUser
    {
        $token = $sessionToken ?? $this->defaultToken;
        if (empty($token)) {
            Log::error('VCRM token missing');
            throw new RuntimeException('VCRM token is required');
        }

        $url = "{$this->apiUrl}/users";

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(10)
                ->get($url, ['phone' => $phoneNumber]);

            $response->throw();

            // API возвращает коллекцию пользователей, возьмем первого
            $data = (array) data_get($response->json(), 'data', []);
            $payload = count($data) > 0 ? (array) $data[0] : [];

            if (empty($payload)) {
                return false;
            }

            return VCRMUser::fromArray($payload);
        } catch (ConnectionException $e) {
            Log::error('VCRM connection failed', [
                'url' => $url,
                'msg' => $e->getMessage(),
            ]);
            return (object) [];
        } catch (RequestException $e) {
            Log::error('VCRM request error', [
                'url' => $url,
                'status' => $e->response?->status(),
            ]);
            throw $e;
        }
    }
}
