<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class SessionController extends Controller
{
    public function store(Request $req)
    {
        $req->validate([
            'login'    => ['required', 'min:4', 'max:64', 'regex:/^[a-zA-Z0-9]*\.?[a-zA-Z0-9]*$/'],
            'password' => 'required|min:6|max:255',
        ]);

        $user = User::where('login', $req->login)->first();
        if (! $user || ! Hash::check($req->password, $user->password)) {
            return response()->json(['message' => 'Неверные данные'], 401);
        }

        $token = $user->createToken('user-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'login' => $user->login,
                'full_name' => $user->full_name,
                'role' => $user->role,
                'dealership_id' => $user->dealership_id,
                'telegram_id' => $user->telegram_id,
                'phone' => $user->phone,
                'dealerships' => $user->dealerships,
            ],
        ]);
    }

    public function destroy(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Сессия завершена']);
    }

    public function current(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'login' => $user->login,
                'full_name' => $user->full_name,
                'role' => $user->role,
                'dealership_id' => $user->dealership_id,
                'telegram_id' => $user->telegram_id,
                'phone' => $user->phone,
                'dealerships' => $user->dealerships,
            ],
        ]);
    }
}
