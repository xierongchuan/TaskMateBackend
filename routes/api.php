<?php

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use App\Models\User;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/login', function (Request $request) {
    $request->validate([
        'email' => 'required|email',
        'password' => 'required',
        'device_name' => 'required',
    ]);

    $user = User::where('email', $request->email)->first();

    if (! $user || ! Hash::check($request->password, $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.'],
        ]);
    }

    $newToken = $user->createToken($request->device_name);
    $newToken->accessToken->expires_at = Carbon::now()->addMonths(6);
    $newToken->accessToken->save();

    $response = [
        'user' => $user,
        'token' => explode('|', $newToken->plainTextToken)[1],
        'expires_at' => $newToken->accessToken->expires_at,
    ];

    return json_encode($response);
});
