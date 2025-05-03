<?php

namespace App\Services;

use Carbon\Carbon;
use App\Dto\TokenResponse;

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
