<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
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

        // Фильтр по пользователю
        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->input('actor_id'));
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

        // Enrich with actor names
        $actorIds = $logs->pluck('actor_id')->unique()->filter()->values()->toArray();
        $actors = User::whereIn('id', $actorIds)->get()->keyBy('id');

        $logsData = $logs->getCollection()->map(function (AuditLog $log) use ($actors) {
            $data = $log->toArray();
            $data['actor'] = $log->actor_id && isset($actors[$log->actor_id])
                ? [
                    'id' => $actors[$log->actor_id]->id,
                    'full_name' => $actors[$log->actor_id]->full_name,
                    'email' => $actors[$log->actor_id]->email,
                ]
                : null;
            return $data;
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
        $allowedTables = ['tasks', 'task_responses', 'shifts'];
        if (!in_array($tableName, $allowedTables)) {
            return response()->json([
                'message' => 'Таблица не поддерживается'
            ], 400);
        }

        $logs = AuditLog::where('table_name', $tableName)
            ->where('record_id', $recordId)
            ->orderByDesc('created_at')
            ->get();

        // Enrich with actor names
        $actorIds = $logs->pluck('actor_id')->unique()->filter()->values()->toArray();
        $actors = User::whereIn('id', $actorIds)->get()->keyBy('id');

        $logsData = $logs->map(function (AuditLog $log) use ($actors) {
            $data = $log->toArray();
            $data['actor'] = $log->actor_id && isset($actors[$log->actor_id])
                ? [
                    'id' => $actors[$log->actor_id]->id,
                    'full_name' => $actors[$log->actor_id]->full_name,
                    'email' => $actors[$log->actor_id]->email,
                ]
                : null;
            return $data;
        });

        return response()->json([
            'data' => $logsData,
        ]);
    }
}
