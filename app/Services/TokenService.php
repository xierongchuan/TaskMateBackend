<?php

namespace App\Services;

use App\Dto\TokenResponse;
use Carbon\Carbon;

class TokenService
{
    public function __construct()
    {
        //
    }

    public function createUserToken($user, $deviceName): TokenResponse
    {
        // Create a token for the user
        $token = $user->createToken($deviceName);

        // Set the token expiration date
        $token->accessToken->expires_at = Carbon::now()->addMonths(6);
        $token->accessToken->save();

        return new TokenResponse(
            token: explode('|', $token->plainTextToken)[1],
            expiresAt: $token->accessToken->expires_at
        );
    }
}
