<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Helpers\TimeHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTaskRequest;
use App\Http\Requests\Api\V1\UpdateTaskRequest;
use App\Models\Shift;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Services\SettingsService;
use App\Services\TaskFilterService;
use App\Services\TaskProofService;
use App\Services\TaskService;
use App\Services\TaskVerificationService;
use App\Jobs\StoreTaskSharedProofsJob;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class TaskController extends Controller
{
    use HasDealershipAccess;

    public function __construct(
        private readonly TaskService $taskService,
        private readonly TaskFilterService $taskFilterService,
        private readonly TaskProofService $taskProofService,
        private readonly TaskVerificationService $taskVerificationService
    ) {}

    /**
     * Получает список задач с фильтрацией и пагинацией.
     *
     * @param  Request  $request  HTTP-запрос с параметрами фильтрации
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
            ],
        ]);
    }

    /**
     * Получает детальную информацию о задаче.
     *
     * @param  int|string  $id  ID задачи
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $task = Task::with([
            'creator',
            'dealership',
            'assignments.user',
            'responses.user',
            'responses.proofs',
            'responses.verifier',
            'sharedProofs',
        ])->find($id);

        if (! $task) {
            return response()->json([
                'message' => 'Задача не найдена',
            ], 404);
        }

        /** @var \App\Models\User $currentUser */
        $currentUser = auth()->user();

        // Security check: Access scope
        if (! $this->isOwner($currentUser)) {
            // Check visibility: dealership match OR created by me OR assigned to me
            $isCreator = $task->creator_id === $currentUser->id;
            $isAssigned = $task->assignments->contains('user_id', $currentUser->id);
            $hasAccess = $this->hasAccessToDealership($currentUser, $task->dealership_id);

            if (! $hasAccess && ! $isCreator && ! $isAssigned) {
                return response()->json([
                    'message' => 'У вас нет доступа к этой задаче',
                ], 403);
            }
        }

        return response()->json($task->toApiArray());
    }

    /**
     * Создаёт новую задачу.
     *
     * @param  StoreTaskRequest  $request  Валидированный запрос
     */
    public function store(StoreTaskRequest $request): JsonResponse
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();

        // Security check: Ensure dealership is accessible
        $validated = $request->validated();
        if (! empty($validated['dealership_id'])) {
            if (! $this->taskService->canAccessDealership($currentUser, (int) $validated['dealership_id'])) {
                return response()->json([
                    'message' => 'Вы не можете создать задачу в чужом автосалоне',
                    'error_type' => 'access_denied',
                ], 403);
            }
        }

        $task = $this->taskService->createTask($validated, $currentUser);

        return response()->json($task->load(['assignments.user'])->toApiArray(), 201);
    }

    /**
     * Обновляет существующую задачу.
     *
     * @param  UpdateTaskRequest  $request  Валидированный запрос
     * @param  int|string  $id  ID задачи
     */
    public function update(UpdateTaskRequest $request, $id): JsonResponse
    {
        $task = Task::find($id);

        if (! $task) {
            return response()->json([
                'message' => 'Задача не найдена',
            ], 404);
        }

        /** @var \App\Models\User $currentUser */
        $currentUser = auth()->user();

        // Security check: Access scope
        if (! $this->taskService->canEditTask($currentUser, $task)) {
            return response()->json([
                'message' => 'У вас нет прав для редактирования этой задачи',
                'error_type' => 'access_denied',
            ], 403);
        }

        $validated = $request->validated();

        // Security check: Ensure new dealership is accessible
        if (isset($validated['dealership_id'])) {
            if (! $this->taskService->canAccessDealership($currentUser, (int) $validated['dealership_id'])) {
                return response()->json([
                    'message' => 'Вы не можете перенести задачу в чужой автосалон',
                    'error_type' => 'access_denied',
                ], 403);
            }
        }

        $task = $this->taskService->updateTask($task, $validated);

        return response()->json($task->load(['assignments.user', 'responses.user'])->toApiArray());
    }

    /**
     * Удаляет задачу.
     *
     * @param  int|string  $id  ID задачи
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $task = Task::find($id);

        if (! $task) {
            return response()->json([
                'message' => 'Задача не найдена',
            ], 404);
        }

        /** @var \App\Models\User $currentUser */
        $currentUser = auth()->user();

        // Security check: Access scope
        if (! $this->isOwner($currentUser)) {
            $hasAccess = $this->hasAccessToDealership($currentUser, $task->dealership_id);
            if (! $hasAccess && $task->creator_id !== $currentUser->id) {
                return response()->json([
                    'message' => 'У вас нет прав для удаления этой задачи',
                ], 403);
            }
        }

        // Delete task assignments (they will be automatically deleted due to foreign key constraints)
        TaskAssignment::where('task_id', $task->id)->delete();

        // Delete the task
        $task->delete();

        return response()->json([
            'message' => 'Задача успешно удалена',
        ]);
    }

    /**
     * Обновляет статус задачи.
     *
     * Поддерживает загрузку файлов доказательств для задач типа completion_with_proof.
     *
     * @param  Request  $request  HTTP-запрос со статусом и файлами
     * @param  int|string  $id  ID задачи
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, $id)
    {
        $task = Task::with(['assignments'])->find($id);

        if (! $task) {
            return response()->json([
                'message' => 'Задача не найдена',
            ], 404);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:pending,acknowledged,pending_review,completed',
            'complete_for_all' => 'sometimes|boolean',
            'proof_files' => 'sometimes|array|max:'.TaskProofService::MAX_FILES_PER_RESPONSE,
            'proof_files.*' => 'file|max:102400', // 100 MB max per file
        ]);

        $status = $validated['status'];
        $completeForAll = $validated['complete_for_all'] ?? false;
        /** @var \App\Models\User $user */
        $user = auth()->user();

        // Для задач с доказательством: проверяем наличие файлов
        if ($task->response_type === 'completion_with_proof') {
            // При попытке завершить задачу (не pending) требуется минимум 1 файл
            if (in_array($status, ['pending_review', 'completed']) && ! $request->hasFile('proof_files')) {
                // Проверяем, есть ли уже загруженные файлы
                $existingResponse = $task->responses()->where('user_id', $user->id)->first();
                $hasExistingProofs = $existingResponse && $existingResponse->proofs()->exists();

                if (! $hasExistingProofs) {
                    return response()->json([
                        'message' => 'Для выполнения этой задачи необходимо загрузить доказательство',
                    ], 422);
                }
            }

            // Для задач с доказательством автоматически ставим pending_review при загрузке файлов
            if ($request->hasFile('proof_files') && $status === 'completed') {
                $status = 'pending_review';
            }
        }

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

            if ($requiresShift && ! $openShift) {
                // Managers and owners can complete without open shift
                if (! in_array($user->role, [Role::MANAGER, Role::OWNER])) {
                    return response()->json([
                        'message' => 'Для выполнения задачи необходимо открыть смену',
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
                // Reset task: remove all responses and their proofs
                foreach ($task->responses as $response) {
                    $this->taskProofService->deleteAllProofs($response);
                }
                $task->responses()->delete();
                break;

            case 'acknowledged':
                // Подтверждение для notification типа задач
                $task->responses()->updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'status' => 'acknowledged',
                        'responded_at' => TimeHelper::nowUtc(),
                        'shift_id' => $shiftId,
                        'completed_during_shift' => $completedDuringShift,
                    ]
                );
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

                    // Загрузка файлов как ОБЩИХ файлов задачи (не привязаны к пользователю)
                    if ($request->hasFile('proof_files')) {
                        $files = $request->file('proof_files');
                        $filesData = [];

                        foreach ($files as $file) {
                            // Сохраняем во временное хранилище
                            $tempPath = $file->store('temp/task_proofs');

                            $filesData[] = [
                                'path' => $tempPath,
                                'original_name' => $file->getClientOriginalName(),
                                'mime' => $file->getMimeType(),
                                'size' => $file->getSize(),
                            ];
                        }

                        // Обеспечиваем group-write на temp-каталог для queue worker (www-data)
                        $tempDir = Storage::path('temp/task_proofs');
                        if (is_dir($tempDir)) {
                            chmod($tempDir, 0775);
                        }

                        // Запускаем Job для асинхронной обработки
                        StoreTaskSharedProofsJob::dispatch(
                            $task->id,
                            $filesData,
                            $task->dealership_id
                        );
                    }
                } else {
                    // Проверяем, это повторная отправка после отклонения
                    $existingResponse = $task->responses()->where('user_id', $user->id)->first();
                    $isResubmission = $existingResponse && $existingResponse->status === 'rejected';

                    // Update or create response for current user only
                    // При любом update очищаем поля верификации
                    $taskResponse = $task->responses()->updateOrCreate(
                        ['user_id' => $user->id],
                        [
                            'status' => $status,
                            'responded_at' => TimeHelper::nowUtc(),
                            'shift_id' => $shiftId,
                            'completed_during_shift' => $completedDuringShift,
                            'verified_at' => null,
                            'verified_by' => null,
                            'rejection_reason' => null,
                        ]
                    );

                    // Загрузка файлов доказательств
                    if ($request->hasFile('proof_files')) {
                        try {
                            $this->taskProofService->storeProofs(
                                $taskResponse,
                                $request->file('proof_files'),
                                $task->dealership_id
                            );

                            // Записываем в историю верификации
                            if ($isResubmission) {
                                $this->taskVerificationService->recordResubmission($taskResponse, $user);
                            } else {
                                $this->taskVerificationService->recordSubmission($taskResponse, $user);
                            }
                        } catch (InvalidArgumentException $e) {
                            return response()->json([
                                'message' => $e->getMessage(),
                            ], 422);
                        }
                    }
                }
                break;
        }

        return response()->json(
            $task->refresh()
                ->load(['assignments.user', 'responses.user', 'responses.proofs', 'responses.verifier', 'sharedProofs'])
                ->toApiArray()
        );
    }

    /**
     * Получает историю выполненных задач текущего пользователя.
     *
     * @param  Request  $request  HTTP-запрос с параметрами фильтрации
     */
    public function myHistory(Request $request): JsonResponse
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();

        $query = Task::with([
            'creator',
            'dealership',
            'assignments.user',
            'responses' => function ($q) use ($currentUser) {
                $q->where('user_id', $currentUser->id)->with(['proofs', 'verifier']);
            },
        ])
            ->whereHas('assignments', function ($q) use ($currentUser) {
                $q->where('user_id', $currentUser->id);
            })
            ->whereHas('responses', function ($q) use ($currentUser) {
                $q->where('user_id', $currentUser->id);
            });

        // Фильтр по статусу ответа
        if ($request->filled('response_status')) {
            $status = $request->input('response_status');
            $query->whereHas('responses', function ($q) use ($currentUser, $status) {
                $q->where('user_id', $currentUser->id)->where('status', $status);
            });
        }

        // Фильтр по dealership
        if ($request->filled('dealership_id')) {
            $query->where('dealership_id', $request->input('dealership_id'));
        }

        // Сортировка
        $query->orderByDesc('updated_at');

        // Пагинация
        $perPage = $request->input('per_page', 15);
        $tasks = $query->paginate((int) $perPage);

        // Transform tasks to API format
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
            ],
        ]);
    }
}
