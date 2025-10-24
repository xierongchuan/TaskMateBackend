<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserRegistrationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'login' => [
                'required',
                'string',
                'min:4',
                'max:255',
                'unique:users,login'
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:255',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[A-Za-z\d@$!%*?&]/'
            ],
            'full_name' => [
                'required',
                'string',
                'min:2',
                'max:255'
            ],
            'phone' => [
                'required',
                'string',
                'regex:/^\+?[\d\s\-\(\)]+$/',
                'max:20'
            ],
            'role' => [
                'required',
                'string',
                Rule::in(Role::values())
            ],
            'telegram_id' => [
                'nullable',
                'integer',
                'unique:users,telegram_id'
            ],
            'dealership_id' => [
                'nullable',
                'integer',
                'exists:auto_dealerships,id'
            ]
        ], [
            'login.required' => 'Логин обязателен',
            'login.min' => 'Логин должен содержать минимум 4 символа',
            'login.unique' => 'Такой логин уже существует',
            'password.required' => 'Пароль обязателен',
            'password.min' => 'Пароль должен содержать минимум 8 символов',
            'password.regex' => 'Пароль должен содержать минимум одну заглавную букву, одну строчную букву и одну цифру',
            'full_name.required' => 'Полное имя обязательно',
            'full_name.min' => 'Полное имя должно содержать минимум 2 символа',
            'phone.required' => 'Телефон обязателен',
            'phone.regex' => 'Некорректный формат телефона',
            'role.required' => 'Роль обязательна',
            'role.in' => 'Некорректная роль',
            'telegram_id.unique' => 'Этот Telegram ID уже используется',
            'dealership_id.exists' => 'Автосалон не найден',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        try {
            $user = User::create([
                'login' => $validated['login'],
                'password' => Hash::make($validated['password']),
                'full_name' => $validated['full_name'],
                'phone' => $validated['phone'],
                'role' => $validated['role'],
                'telegram_id' => $validated['telegram_id'] ?? 0,
                'dealership_id' => $validated['dealership_id'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Сотрудник успешно создан',
                'data' => new UserResource($user)
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании сотрудника',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера'
            ], 500);
        }
    }
}