<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\AutoDealership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * Список поддерживаемых таблиц для аудита.
     */
    private const ALLOWED_TABLES = [
        'tasks',
        'task_responses',
        'shifts',
        'users',
        'auto_dealerships',
    ];

    /**
     * Получает список логов аудита с пагинацией и фильтрацией.
     *
     * @param Request $request HTTP-запрос
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()
            ->orderByDesc('created_at');

        // Фильтр по таблице
        if ($request->filled('table_name')) {
            $query->where('table_name', $request->input('table_name'));
        }

        // Фильтр по действию
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        // Фильтр по пользователю (actor)
        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->input('actor_id'));
        }

        // Фильтр по автосалону
        if ($request->filled('dealership_id')) {
            $query->where('dealership_id', $request->input('dealership_id'));
        }

        // Фильтр по дате
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        // Фильтр по record_id
        if ($request->filled('record_id')) {
            $query->where('record_id', $request->input('record_id'));
        }

        $perPage = $request->input('per_page', 25);
        $logs = $query->paginate((int) $perPage);

        // Enrich данными: actors и dealerships
        $actorIds = $logs->pluck('actor_id')->unique()->filter()->values()->toArray();
        $actors = User::whereIn('id', $actorIds)->get()->keyBy('id');

        $dealershipIds = $logs->pluck('dealership_id')->unique()->filter()->values()->toArray();
        $dealerships = AutoDealership::whereIn('id', $dealershipIds)->get()->keyBy('id');

        $logsData = $logs->getCollection()->map(function (AuditLog $log) use ($actors, $dealerships) {
            return $this->formatLogEntry($log, $actors, $dealerships);
        });

        return response()->json([
            'data' => $logsData,
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'per_page' => $logs->perPage(),
            'total' => $logs->total(),
        ]);
    }

    /**
     * Получает историю изменений конкретной записи.
     *
     * @param string $tableName Название таблицы
     * @param int $recordId ID записи
     * @return JsonResponse
     */
    public function forRecord(string $tableName, int $recordId): JsonResponse
    {
        // Validate table name to prevent arbitrary table access
        if (!in_array($tableName, self::ALLOWED_TABLES)) {
            return response()->json([
                'message' => 'Таблица не поддерживается'
            ], 400);
        }

        $logs = AuditLog::where('table_name', $tableName)
            ->where('record_id', $recordId)
            ->orderByDesc('created_at')
            ->get();

        // Enrich данными: actors и dealerships
        $actorIds = $logs->pluck('actor_id')->unique()->filter()->values()->toArray();
        $actors = User::whereIn('id', $actorIds)->get()->keyBy('id');

        $dealershipIds = $logs->pluck('dealership_id')->unique()->filter()->values()->toArray();
        $dealerships = AutoDealership::whereIn('id', $dealershipIds)->get()->keyBy('id');

        $logsData = $logs->map(function (AuditLog $log) use ($actors, $dealerships) {
            return $this->formatLogEntry($log, $actors, $dealerships);
        });

        return response()->json([
            'data' => $logsData,
        ]);
    }

    /**
     * Получает список пользователей для фильтра (те, кто совершал действия).
     *
     * @return JsonResponse
     */
    public function actors(): JsonResponse
    {
        $actorIds = AuditLog::query()
            ->whereNotNull('actor_id')
            ->distinct()
            ->pluck('actor_id');

        $actors = User::whereIn('id', $actorIds)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'email']);

        return response()->json([
            'data' => $actors->map(fn ($user) => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
            ]),
        ]);
    }

    /**
     * Форматирует запись лога для ответа API.
     *
     * @param AuditLog $log
     * @param \Illuminate\Support\Collection $actors
     * @param \Illuminate\Support\Collection $dealerships
     * @return array
     */
    private function formatLogEntry(AuditLog $log, $actors, $dealerships): array
    {
        $data = $log->toArray();

        // Дата уже в локальном времени (app.timezone = Asia/Yekaterinburg)
        // Форматируем с offset для корректного парсинга на фронтенде
        if ($log->created_at) {
            $data['created_at'] = $log->created_at->format('Y-m-d\TH:i:s') . '+05:00';
        }

        // Добавляем информацию об actor
        $data['actor'] = $log->actor_id && isset($actors[$log->actor_id])
            ? [
                'id' => $actors[$log->actor_id]->id,
                'full_name' => $actors[$log->actor_id]->full_name,
                'email' => $actors[$log->actor_id]->email,
            ]
            : null;

        // Добавляем информацию о dealership
        $data['dealership'] = $log->dealership_id && isset($dealerships[$log->dealership_id])
            ? [
                'id' => $dealerships[$log->dealership_id]->id,
                'name' => $dealerships[$log->dealership_id]->name,
            ]
            : null;

        // Добавляем человекочитаемое название таблицы
        $data['table_label'] = $this->getTableLabel($log->table_name);

        // Добавляем человекочитаемое название действия
        $data['action_label'] = $this->getActionLabel($log->action);

        return $data;
    }

    /**
     * Возвращает человекочитаемое название таблицы.
     *
     * @param string $tableName
     * @return string
     */
    private function getTableLabel(string $tableName): string
    {
        return match ($tableName) {
            'tasks' => 'Задачи',
            'task_responses' => 'Ответы на задачи',
            'shifts' => 'Смены',
            'users' => 'Пользователи',
            'auto_dealerships' => 'Автосалоны',
            default => $tableName,
        };
    }

    /**
     * Возвращает человекочитаемое название действия.
     *
     * @param string $action
     * @return string
     */
    private function getActionLabel(string $action): string
    {
        return match ($action) {
            'created' => 'Создание',
            'updated' => 'Обновление',
            'deleted' => 'Удаление',
            default => $action,
        };
    }
}
