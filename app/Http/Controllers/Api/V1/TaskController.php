<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\TimeHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTaskRequest;
use App\Http\Requests\Api\V1\UpdateTaskRequest;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\Shift;
use App\Services\SettingsService;
use App\Services\TaskService;
use App\Services\TaskFilterService;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use App\Enums\Role;

class TaskController extends Controller
{
    use HasDealershipAccess;

    public function __construct(
        private readonly TaskService $taskService,
        private readonly TaskFilterService $taskFilterService
    ) {}

    /**
     * Получает список задач с фильтрацией и пагинацией.
     *
     * @param Request $request HTTP-запрос с параметрами фильтрации
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();

        $tasks = $this->taskFilterService->getFilteredTasks($request, $currentUser);

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

    /**
     * Получает детальную информацию о задаче.
     *
     * @param int|string $id ID задачи
     * @return \Illuminate\Http\JsonResponse
     */
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

        /** @var \App\Models\User $currentUser */
        $currentUser = auth()->user();

        // Security check: Access scope
        if (!$this->isOwner($currentUser)) {
            // Check visibility: dealership match OR created by me OR assigned to me
            $isCreator = $task->creator_id === $currentUser->id;
            $isAssigned = $task->assignments->contains('user_id', $currentUser->id);
            $hasAccess = $this->hasAccessToDealership($currentUser, $task->dealership_id);

            if (!$hasAccess && !$isCreator && !$isAssigned) {
                return response()->json([
                    'message' => 'У вас нет доступа к этой задаче'
                ], 403);
            }
        }

        return response()->json($task->toApiArray());
    }

    /**
     * Создаёт новую задачу.
     *
     * @param StoreTaskRequest $request Валидированный запрос
     * @return JsonResponse
     */
    public function store(StoreTaskRequest $request): JsonResponse
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();

        // Security check: Ensure dealership is accessible
        $validated = $request->validated();
        if (!empty($validated['dealership_id'])) {
            if (!$this->taskService->canAccessDealership($currentUser, (int) $validated['dealership_id'])) {
                return response()->json([
                    'message' => 'Вы не можете создать задачу в чужом автосалоне',
                    'error_type' => 'access_denied'
                ], 403);
            }
        }

        $task = $this->taskService->createTask($validated, $currentUser);

        return response()->json($task->load(['assignments.user'])->toApiArray(), 201);
    }

    /**
     * Обновляет существующую задачу.
     *
     * @param UpdateTaskRequest $request Валидированный запрос
     * @param int|string $id ID задачи
     * @return JsonResponse
     */
    public function update(UpdateTaskRequest $request, $id): JsonResponse
    {
        $task = Task::find($id);

        if (!$task) {
            return response()->json([
                'message' => 'Задача не найдена'
            ], 404);
        }

        /** @var \App\Models\User $currentUser */
        $currentUser = auth()->user();

        // Security check: Access scope
        if (!$this->taskService->canEditTask($currentUser, $task)) {
            return response()->json([
                'message' => 'У вас нет прав для редактирования этой задачи',
                'error_type' => 'access_denied'
            ], 403);
        }

        $validated = $request->validated();

        // Security check: Ensure new dealership is accessible
        if (isset($validated['dealership_id'])) {
            if (!$this->taskService->canAccessDealership($currentUser, (int) $validated['dealership_id'])) {
                return response()->json([
                    'message' => 'Вы не можете перенести задачу в чужой автосалон',
                    'error_type' => 'access_denied'
                ], 403);
            }
        }

        $task = $this->taskService->updateTask($task, $validated);

        return response()->json($task->load(['assignments.user', 'responses.user'])->toApiArray());
    }

    /**
     * Удаляет задачу.
     *
     * @param int|string $id ID задачи
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $task = Task::find($id);

        if (!$task) {
             return response()->json([
                'message' => 'Задача не найдена'
            ], 404);
        }

        /** @var \App\Models\User $currentUser */
        $currentUser = auth()->user();

        // Security check: Access scope
        if (!$this->isOwner($currentUser)) {
            $hasAccess = $this->hasAccessToDealership($currentUser, $task->dealership_id);
            if (!$hasAccess && $task->creator_id !== $currentUser->id) {
                return response()->json([
                    'message' => 'У вас нет прав для удаления этой задачи'
                ], 403);
            }
        }

        // Delete task assignments (they will be automatically deleted due to foreign key constraints)
        TaskAssignment::where('task_id', $task->id)->delete();

        // Delete the task
        $task->delete();

        return response()->json([
            'message' => 'Задача успешно удалена'
        ]);
    }

    /**
     * Обновляет статус задачи.
     *
     * @param Request $request HTTP-запрос со статусом
     * @param int|string $id ID задачи
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, $id)
    {
        $task = Task::with(['assignments'])->find($id);

        if (!$task) {
            return response()->json([
                'message' => 'Задача не найдена'
            ], 404);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:pending,pending_review,completed',
            'complete_for_all' => 'sometimes|boolean',
        ]);

        $status = $validated['status'];
        $completeForAll = $validated['complete_for_all'] ?? false;
        /** @var \App\Models\User $user */
        $user = auth()->user();

        // Hybrid mode: check if open shift is required
        $shiftId = null;
        $completedDuringShift = false;

        if (in_array($status, ['pending_review', 'completed'])) {
            $settingsService = app(SettingsService::class);
            $requiresShift = (bool) $settingsService->getSettingWithFallback(
                'task_requires_open_shift',
                $task->dealership_id,
                false
            );

            // Find user's open shift
            $openShift = Shift::where('user_id', $user->id)
                ->whereNull('shift_end')
                ->where('status', 'open')
                ->first();

            if ($requiresShift && !$openShift) {
                // Managers and owners can complete without open shift
                if (!in_array($user->role, [Role::MANAGER, Role::OWNER])) {
                    return response()->json([
                        'message' => 'Для выполнения задачи необходимо открыть смену'
                    ], 422);
                }
            }

            if ($openShift) {
                $shiftId = $openShift->id;
                $completedDuringShift = true;
            }
        }

        switch ($status) {
            case 'pending':
                // Reset task: remove all responses
                $task->responses()->delete();
                break;

            case 'pending_review':
            case 'completed':
                // If manager/owner wants to complete for all assignees
                if ($completeForAll && in_array($user->role, [Role::MANAGER, Role::OWNER])) {
                    // Create responses for ALL assigned users
                    $assignedUserIds = $task->assignments->pluck('user_id')->unique()->toArray();

                    foreach ($assignedUserIds as $assignedUserId) {
                        $task->responses()->updateOrCreate(
                            ['user_id' => $assignedUserId],
                            [
                                'status' => $status,
                                'responded_at' => TimeHelper::nowUtc(),
                                'shift_id' => null, // Manager completes on behalf
                                'completed_during_shift' => false,
                            ]
                        );
                    }
                } else {
                    // Update or create response for current user only
                    $task->responses()->updateOrCreate(
                        ['user_id' => $user->id],
                        [
                            'status' => $status,
                            'responded_at' => TimeHelper::nowUtc(),
                            'shift_id' => $shiftId,
                            'completed_during_shift' => $completedDuringShift,
                        ]
                    );
                }
                break;
        }

        return response()->json($task->refresh()->load(['assignments.user', 'responses.user'])->toApiArray());
    }
}
