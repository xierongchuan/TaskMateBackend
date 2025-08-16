<?php

declare(strict_types=1);

namespace App\Http\Services\VCRM;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use App\DTO\VCRM\User as VCRMUser;
use App\DTO\VCRM\Company as VCRMCompany;
use App\DTO\VCRM\Department as VCRMDepartment;
use App\DTO\VCRM\Post as VCRMPost;

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
            Log::error('VCRM token missing', ['userId' => $userId]);
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
}
