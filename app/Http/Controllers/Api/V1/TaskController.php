<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskAssignment;
use Illuminate\Http\Request;
use Carbon\Carbon;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', '15');

        // Получаем все параметры фильтрации
        $dealershipId = $request->query('dealership_id');
        $taskType = $request->query('task_type');
        $isActive = $request->query('is_active');
        $tags = $request->query('tags');
        $creatorId = $request->query('creator_id');
        $responseType = $request->query('response_type');
        $deadlineFrom = $request->query('deadline_from');
        $deadlineTo = $request->query('deadline_to');
        $hasDeadline = $request->query('has_deadline');
        $search = $request->query('search');
        $status = $request->query('status');

        $query = Task::with(['creator', 'dealership', 'assignments.user', 'responses']);

        // Фильтрация по автосалону
        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        // Фильтрация по типу задачи
        if ($taskType) {
            $query->where('task_type', $taskType);
        }

        // Фильтрация по активности
        if ($isActive !== null) {
            $isActiveValue = filter_var($isActive, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActiveValue !== null) {
                $query->where('is_active', $isActiveValue);
            }
        }

        // Фильтрация по создателю
        if ($creatorId) {
            $query->where('creator_id', $creatorId);
        }

        // Фильтрация по типу ответа
        if ($responseType) {
            $query->where('response_type', $responseType);
        }

        // Фильтрация по тегам
        if ($tags) {
            $tagsArray = is_array($tags) ? $tags : explode(',', $tags);
            $tagsArray = array_map('trim', $tagsArray);
            $query->where(function ($q) use ($tagsArray) {
                foreach ($tagsArray as $tag) {
                    $q->orWhereJsonContains('tags', $tag);
                }
            });
        }

        // Фильтрация по дедлайну
        if ($deadlineFrom) {
            try {
                $query->where('deadline', '>=', Carbon::parse($deadlineFrom));
            } catch (\Exception $e) {
                // Некорректная дата - игнорируем фильтр
            }
        }

        if ($deadlineTo) {
            try {
                $query->where('deadline', '<=', Carbon::parse($deadlineTo));
            } catch (\Exception $e) {
                // Некорректная дата - игнорируем фильтр
            }
        }

        // Фильтрация по наличию дедлайна
        if ($hasDeadline !== null) {
            $hasDeadlineValue = filter_var($hasDeadline, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($hasDeadlineValue !== null) {
                if ($hasDeadlineValue) {
                    $query->whereNotNull('deadline');
                } else {
                    $query->whereNull('deadline');
                }
            }
        }

        // Поиск по названию, описанию, комментарию и тегам
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%")
                  ->orWhere('comment', 'LIKE', "%{$search}%")
                  // Search in tags JSON array - cast to text for PostgreSQL
                  ->orWhereRaw("tags::text LIKE ?", ["%{$search}%"]);
            });
        }

        // Фильтрация по статусу задачи (Bug #3 - код корректный, проверено)
        // Поддерживаемые статусы: active, completed, overdue, postponed, pending, acknowledged
        if ($status) {
            $now = Carbon::now();

            switch (strtolower($status)) {
                case 'active':
                    $query->where('is_active', true)
                          ->whereNull('archived_at');
                    break;

                case 'completed':
                    $query->whereHas('responses', function ($q) {
                        $q->where('status', 'completed');
                    });
                    break;

                case 'overdue':
                    $query->where('is_active', true)
                          ->whereNotNull('deadline')
                          ->where('deadline', '<', $now)
                          ->whereDoesntHave('responses', function ($q) {
                              $q->where('status', 'completed');
                          });
                    break;

                case 'postponed':
                    $query->where('postpone_count', '>', 0)
                          ->where('is_active', true);
                    break;

                case 'pending':
                    $query->where('is_active', true)
                          ->whereDoesntHave('responses', function ($q) {
                              $q->whereIn('status', ['completed', 'acknowledged']);
                          });
                    break;

                case 'acknowledged':
                    $query->whereHas('responses', function ($q) {
                        $q->where('status', 'acknowledged');
                    });
                    break;
            }
        }

        // Исключаем архивные задачи (у которых есть archived_at)
        $query->whereNull('archived_at');

        $tasks = $query->orderByDesc('created_at')->paginate($perPage);

        // Transform tasks to use UTC+5 timezone
        $tasksData = $tasks->getCollection()->map(function ($task) {
            return $task->toApiArray();
        });

        return response()->json([
            'data' => $tasksData,
            'current_page' => $tasks->currentPage(),
            'last_page' => $tasks->lastPage(),
            'per_page' => $tasks->perPage(),
            'total' => $tasks->total(),
            'links' => [
                'first' => $tasks->url(1),
                'last' => $tasks->url($tasks->lastPage()),
                'prev' => $tasks->previousPageUrl(),
                'next' => $tasks->nextPageUrl(),
            ]
        ]);
    }

    public function show($id)
    {
        $task = Task::with([
            'creator',
            'dealership',
            'assignments.user',
            'responses.user'
        ])->find($id);

        if (!$task) {
            return response()->json([
                'message' => 'Задача не найдена'
            ], 404);
        }

        return response()->json($task->toApiArray());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'dealership_id' => 'nullable|exists:auto_dealerships,id',
            'appear_date' => 'nullable|string',
            'deadline' => 'nullable|string',
            'recurrence' => 'nullable|string|in:daily,weekly,monthly',
            'task_type' => 'required|string|in:individual,group',
            'response_type' => 'required|string|in:acknowledge,complete',
            'tags' => 'nullable|array',
            'assignments' => 'nullable|array',
            'assignments.*' => 'exists:users,id',
        ]);

        $task = Task::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'comment' => $validated['comment'] ?? null,
            'creator_id' => auth()->id(),
            'dealership_id' => $validated['dealership_id'] ?? null,
            'appear_date' => $validated['appear_date'] ?? null,
            'deadline' => $validated['deadline'] ?? null,
            'recurrence' => $validated['recurrence'] ?? null,
            'task_type' => $validated['task_type'],
            'response_type' => $validated['response_type'],
            'tags' => $validated['tags'] ?? null,
        ]);

        // Assign users
        if (!empty($validated['assignments'])) {
            foreach ($validated['assignments'] as $userId) {
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $userId,
                ]);
            }
        }

        return response()->json($task->load(['assignments.user'])->toApiArray(), 201);
    }

    public function update(Request $request, $id)
    {
        $task = Task::find($id);

        if (!$task) {
            return response()->json([
                'message' => 'Задача не найдена'
            ], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'dealership_id' => 'nullable|exists:auto_dealerships,id',
            'appear_date' => 'nullable|string',
            'deadline' => 'nullable|string',
            'recurrence' => 'nullable|string|in:daily,weekly,monthly',
            'task_type' => 'sometimes|required|string|in:individual,group',
            'response_type' => 'sometimes|required|string|in:acknowledge,complete',
            'tags' => 'nullable|array',
            'is_active' => 'boolean',
            'assignments' => 'nullable|array',
            'assignments.*' => 'exists:users,id',
        ]);

        $task->update($validated);

        // Update user assignments if provided
        if (array_key_exists('assignments', $validated)) {
            // Remove existing assignments
            TaskAssignment::where('task_id', $task->id)->delete();

            // Add new assignments
            if (!empty($validated['assignments'])) {
                foreach ($validated['assignments'] as $userId) {
                    TaskAssignment::create([
                        'task_id' => $task->id,
                        'user_id' => $userId,
                    ]);
                }
            }
        }

        return response()->json($task->load(['assignments.user', 'responses.user'])->toApiArray());
    }

    public function destroy($id)
    {
        $task = Task::find($id);

        if (!$task) {
            return response()->json([
                'message' => 'Задача не найдена'
            ], 404);
        }

        // Delete task assignments (they will be automatically deleted due to foreign key constraints)
        TaskAssignment::where('task_id', $task->id)->delete();

        // Delete the task
        $task->delete();

        return response()->json([
            'message' => 'Задача успешно удалена'
        ]);
    }

    public function postponed(Request $request)
    {
        $dealershipId = $request->query('dealership_id');

        $query = Task::with(['creator', 'dealership', 'responses'])
            ->where('postpone_count', '>', 0)
            ->where('is_active', true);

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        $postponedTasks = $query->orderByDesc('postpone_count')->get();

        // Transform tasks to use UTC+5 timezone
        $tasksData = $postponedTasks->map(function ($task) {
            return $task->toApiArray();
        });

        return response()->json($tasksData);
    }
}
