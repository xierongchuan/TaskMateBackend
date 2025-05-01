<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Carbon\Carbon;
use App\Services\UserService;
use App\Services\TokenService;

class SessionController extends Controller
{

    protected $userService;
    protected $tokenService;

    public function __construct(UserService $userService, TokenService $tokenService)
    {
        $this->userService = $userService;
        $this->tokenService = $tokenService;
    }

    public function create(Request $request)
    {
        // Validate the request
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'device_name' => 'required',

        ]);

        $user = $this->userService->createUserFromRequest($request);

        $token = $this->tokenService->createUserToken($user, $request->device_name);

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'token' => $token->token,
            'expires_at' => $token->expiresAt
        ], 201);
    }

    public function start(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
            'device_name' => 'required|string',
        ]);

        $user = $this->userService->withEmail($request->email);

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $token = $this->tokenService->createUserToken($user, $request->device_name);

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'token' => $token->token,
            'expires_at' => $token->expiresAt
        ], 200);
    }

    public function end(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(
            [
                'status' => 'success',
                'message' => 'User session ended successfully',
            ]
        );
    }

}
