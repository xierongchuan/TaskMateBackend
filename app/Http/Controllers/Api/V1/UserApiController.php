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

        $hasTelegram = (string) $request->query('has_telegram', '');

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

            // Если после нормализации пусто — возвращаем пустую страницу
            if ($normalized === '') {
                return UserResource::collection(collect([]));
            }

            // Определяем драйвер БД
            $driver = config('database.default');

            if ($driver === 'pgsql') {
                $query->whereRaw("regexp_replace(phone, '[^0-9]', '', 'g') LIKE ?", ["%{$normalized}%"]);
            } elseif ($driver === 'mysql') {
                $query->whereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?", ["%{$normalized}%"]);
            } else {
                $query->whereRaw(
                    "REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', '') LIKE ?",
                    ["%{$normalized}%"]
                );
            }
        }

        // Telegram connection filtering
        if ($hasTelegram !== '') {
            if ($hasTelegram === 'connected') {
                $query->whereNotNull('telegram_id');
            } elseif ($hasTelegram === 'not_connected') {
                $query->whereNull('telegram_id');
            }
            // Ignore invalid values silently
        }

        // Eager load dealership relationship if filtering by dealership or include requested
        if ($dealershipId !== '' || $request->query('include_dealership', '') !== '') {
            $query->with('dealership');
        }

        $users = $query->orderByDesc('created_at')->paginate($perPage);
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

    public function update(Request $request, $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь не найден'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'password' => [
                'sometimes',
                'nullable',
                'string',
                'min:8',
                'max:255',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[A-Za-z\d@$!%*?&]/'
            ],
            'full_name' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:255'
            ],
            'phone' => [
                'sometimes',
                'required',
                'string',
                'regex:/^\+?[\d\s\-\(\)]+$/',
                'max:20'
            ],
            'phone_number' => [
                'sometimes',
                'required',
                'string',
                'regex:/^\+?[\d\s\-\(\)]+$/',
                'max:20'
            ],
            'role' => [
                'sometimes',
                'required',
                'string',
                Rule::in(Role::values())
            ],
            'dealership_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:auto_dealerships,id'
            ]
        ], [
            'password.min' => 'Пароль должен содержать минимум 8 символов',
            'password.regex' => 'Пароль должен содержать минимум одну заглавную букву, одну строчную букву и одну цифру',
            'full_name.required' => 'Полное имя обязательно',
            'full_name.min' => 'Полное имя должно содержать минимум 2 символа',
            'phone.required' => 'Телефон обязателен',
            'phone.regex' => 'Некорректный формат телефона',
            'phone_number.required' => 'Телефон обязателен',
            'phone_number.regex' => 'Некорректный формат телефона',
            'role.required' => 'Роль обязательна',
            'role.in' => 'Некорректная роль',
            'dealership_id.exists' => 'Автосалон не найден'
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
            $updateData = [];

            // Only update password if it's provided and not empty
            if (isset($validated['password']) && $validated['password'] !== '' && $validated['password'] !== null) {
                $updateData['password'] = Hash::make($validated['password']);
            }

            if (isset($validated['full_name'])) {
                $updateData['full_name'] = $validated['full_name'];
            }

            // Support both 'phone' and 'phone_number' fields
            if (isset($validated['phone'])) {
                $updateData['phone'] = $validated['phone'];
            } elseif (isset($validated['phone_number'])) {
                $updateData['phone'] = $validated['phone_number'];
            }

            if (isset($validated['role'])) {
                $updateData['role'] = $validated['role'];
            }

            if (isset($validated['dealership_id'])) {
                $updateData['dealership_id'] = $validated['dealership_id'];
            }

            $user->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Данные пользователя успешно обновлены',
                'data' => new UserResource($user)
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при обновлении пользователя',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера'
            ], 500);
        }
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

    public function destroy($id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь не найден'
            ], 404);
        }

        // Проверяем наличие связанных данных
        $relatedData = [];

        if ($user->shifts()->count() > 0) {
            $relatedData['shifts'] = $user->shifts()->count();
        }

        if ($user->taskAssignments()->count() > 0) {
            $relatedData['task_assignments'] = $user->taskAssignments()->count();
        }

        if ($user->taskResponses()->count() > 0) {
            $relatedData['task_responses'] = $user->taskResponses()->count();
        }

        if ($user->createdTasks()->count() > 0) {
            $relatedData['created_tasks'] = $user->createdTasks()->count();
        }

        if ($user->createdLinks()->count() > 0) {
            $relatedData['created_links'] = $user->createdLinks()->count();
        }

        if ($user->replacementsAsReplacing()->count() > 0) {
            $relatedData['replacements_as_replacing'] = $user->replacementsAsReplacing()->count();
        }

        if ($user->replacementsAsReplaced()->count() > 0) {
            $relatedData['replacements_as_replaced'] = $user->replacementsAsReplaced()->count();
        }

        if (!empty($relatedData)) {
            return response()->json([
                'success' => false,
                'message' => 'Невозможно удалить пользователя со связанными данными',
                'related_data' => $relatedData,
                'errors' => [
                    'user' => ['Пользователь имеет связанные записи: ' . implode(', ', array_keys($relatedData))]
                ]
            ], 422);
        }

        try {
            // Удаляем все токены пользователя перед удалением
            $user->tokens()->delete();

            $userName = $user->full_name;
            $user->delete();

            return response()->json([
                'success' => true,
                'message' => "Пользователь '{$userName}' успешно удален"
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении пользователя',
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
