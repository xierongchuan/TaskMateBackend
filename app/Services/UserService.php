<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class UserService
{
    public function __construct()
    {
        //
    }

    public function createUserFromRequest(Request $request): User
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        return $user;
    }

    public function withEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }
}
