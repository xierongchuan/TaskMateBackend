<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    public function register(Request $req)
    {
        $req->validate([
            'login'     => 'required|string|min:4|max:255|unique:users,login',
            'password' => [
                'required',
                'string',
                'min:12',
                'max:255',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/'
            ],
        ]);

        $user = User::create([
            'login'     => $req->login,
            'password'  => Hash::make($req->password),
            'full_name' => '-',
            'telegram_id' => 0,
            'company_id' => 0,
            'phone' => '+0000000000',
            'role'      => Role::USER->value,
        ]);

        $token = $user->createToken('user-token')->plainTextToken;

        return response()->json([
            'message' => 'Пользователь успешно зарегистрирован',
            'token'   => $token,
        ]);
    }
}
