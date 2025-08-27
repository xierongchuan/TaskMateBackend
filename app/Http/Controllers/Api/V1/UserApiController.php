<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class UserApiController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', '15');
        $phone = (string) $request->query('phone', '');

        // Если передан phone -> делаем поиск по нормализованному номеру (только цифры)
        if ($phone !== '') {
            $normalized = $this->normalizePhone($phone);

            // Если после нормализации пусто — возвращаем пустую страницу
            if ($normalized === '') {
                return UserResource::collection(collect([]));
            }

            // Определяем драйвер БД
            $driver = config('database.default');

            $query = User::query();

            if ($driver === 'pgsql') {
                $query->whereRaw("regexp_replace(phone_number, '\\\\D', '', 'g') LIKE ?", ["%{$normalized}%"]);
            } elseif ($driver === 'mysql') {
                $query->whereRaw("REGEXP_REPLACE(phone_number, '[^0-9]', '') LIKE ?", ["%{$normalized}%"]);
            } else {
                $query->whereRaw(
                    "REPLACE(REPLACE(REPLACE(phone_number, ' ', ''), '+', ''), '-', '') LIKE ?",
                    ["%{$normalized}%"]
                );
            }
            $users = $query->orderByDesc('created_at')->paginate($perPage);

            return UserResource::collection($users);
        }

        // Без фильтра — стандартная пагинация
        $users = User::orderByDesc('created_at')->paginate($perPage);
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

    /**
     * Нормализует телефон: убирает все не-цифры.
     * Возвращает строку из цифр или пустую строку.
     */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
