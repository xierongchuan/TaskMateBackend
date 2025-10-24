<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Enums\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserApiController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', '15');

        // Get filter parameters
        $search = (string) $request->query('search', '');
        $login = (string) $request->query('login', '');
        $name = (string) $request->query('name', '');
        $role = (string) $request->query('role', '');
        $dealershipId = (string) $request->query('dealership_id', '');
        $phone = (string) $request->query('phone', '');

        // Support both 'phone' and 'phone_number' parameters
        if ($phone === '') {
            $phone = (string) $request->query('phone_number', '');
        }

        // Debug all query parameters
        Log::info('All query parameters', [
            'all_params' => $request->query(),
            'phone_param' => $phone,
            'phone_raw' => $request->query('phone'),
            'phone_number_raw' => $request->query('phone_number'),
            'phone_empty_check' => $phone !== '',
            'phone_type' => gettype($phone)
        ]);

        $query = User::query();

        // Search by login or name (OR logic)
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('login', 'LIKE', "%{$search}%")
                  ->orWhere('full_name', 'LIKE', "%{$search}%");
            });
        }

        // Exact filters
        if ($login !== '') {
            $query->where('login', $login);
        }

        if ($name !== '') {
            $query->where('full_name', 'LIKE', "%{$name}%");
        }

        if ($role !== '') {
            $query->where('role', $role);
        }

        if ($dealershipId !== '') {
            $query->where('dealership_id', $dealershipId);
        }

        // Phone filtering with normalization (existing logic)
        if ($phone !== '') {
            $normalized = $this->normalizePhone($phone);

            // Debug logging
            Log::info('Phone filter triggered', [
                'original_phone' => $phone,
                'normalized' => $normalized,
                'driver' => config('database.default'),
                'test_normalization' => $this->normalizePhone($phone)
            ]);

            // Если после нормализации пусто — возвращаем пустую страницу
            if ($normalized === '') {
                Log::info('Empty normalized phone, returning empty result');
                return UserResource::collection(collect([]));
            }

            // Определяем драйвер БД
            $driver = config('database.default');

            if ($driver === 'pgsql') {
                $query->whereRaw("regexp_replace(phone, '[^0-9]', '', 'g') LIKE ?", ["%{$normalized}%"]);
                Log::info('PostgreSQL phone filter applied', ['normalized' => $normalized]);
            } elseif ($driver === 'mysql') {
                $query->whereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?", ["%{$normalized}%"]);
                Log::info('MySQL phone filter applied', ['normalized' => $normalized]);
            } else {
                $query->whereRaw(
                    "REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', '') LIKE ?",
                    ["%{$normalized}%"]
                );
                Log::info('Other DB phone filter applied', ['normalized' => $normalized]);
            }
        }

        // Eager load dealership relationship if filtering by dealership or include requested
        if ($dealershipId !== '' || $request->query('include_dealership', '') !== '') {
            $query->with('dealership');
        }

        $users = $query->orderByDesc('created_at')->paginate($perPage);

        // Debug logging
        Log::info('Final query executed', [
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings(),
            'result_count' => $users->count()
        ]);

        return UserResource::collection($users);
    }

    public function show($id)
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'message' => 'Пользователь не найден'
            ], 404);
        }

        return new UserResource($user);
    }

    public function status($id)
    {
        $user = User::find($id);

        // Если пользователь не найден или поле active = false → возвращаем is_active = false
        $isActive = $user && ($user->status == 'active');

        return response()->json([
            'is_active' => (bool) $isActive,
        ]);
    }

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

    /**
     * Нормализует телефон: убирает все не-цифры.
     * Возвращает строку из цифр или пустую строку.
     */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
