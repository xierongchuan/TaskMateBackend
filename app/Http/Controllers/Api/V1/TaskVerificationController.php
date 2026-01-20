<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TaskResponse;
use App\Services\TaskVerificationService;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер для верификации доказательств выполнения задач.
 *
 * Доступен только менеджерам и владельцам.
 */
class TaskVerificationController extends Controller
{
    use HasDealershipAccess;

    public function __construct(
        private readonly TaskVerificationService $verificationService
    ) {}

    /**
     * Одобрить доказательство выполнения.
     *
     * Статус ответа меняется на 'completed'.
     *
     * @param int|string $id ID ответа на задачу (task_response)
     * @return JsonResponse
     */
    public function approve($id): JsonResponse
    {
        $taskResponse = TaskResponse::with(['task', 'proofs'])->find($id);

        if (!$taskResponse) {
            return response()->json([
                'message' => 'Ответ на задачу не найден'
            ], 404);
        }

        /** @var \App\Models\User $currentUser */
        $currentUser = auth()->user();
        $task = $taskResponse->task;

        // Проверка доступа к автосалону
        if (!$this->isOwner($currentUser)) {
            if (!$this->hasAccessToDealership($currentUser, $task->dealership_id)) {
                return response()->json([
                    'message' => 'У вас нет доступа к этой задаче'
                ], 403);
            }
        }

        // Проверка статуса
        if ($taskResponse->status !== 'pending_review') {
            return response()->json([
                'message' => 'Этот ответ не требует верификации'
            ], 422);
        }

        // Проверка наличия доказательств (только для задач с типом completion_with_proof)
        if ($task->response_type === 'completion_with_proof' && $taskResponse->proofs->isEmpty()) {
            return response()->json([
                'message' => 'Нет доказательств для верификации'
            ], 422);
        }

        // Одобряем через сервис (записывает историю)
        $this->verificationService->approve($taskResponse, $currentUser);

        return response()->json([
            'message' => 'Доказательство одобрено',
            'data' => $task->refresh()
                ->load(['assignments.user', 'responses.user', 'responses.proofs', 'responses.verifier'])
                ->toApiArray()
        ]);
    }

    /**
     * Отклонить доказательство выполнения.
     *
     * Статус ответа меняется на 'pending', файлы удаляются.
     *
     * @param Request $request
     * @param int|string $id ID ответа на задачу (task_response)
     * @return JsonResponse
     */
    public function reject(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $taskResponse = TaskResponse::with(['task', 'proofs'])->find($id);

        if (!$taskResponse) {
            return response()->json([
                'message' => 'Ответ на задачу не найден'
            ], 404);
        }

        /** @var \App\Models\User $currentUser */
        $currentUser = auth()->user();
        $task = $taskResponse->task;

        // Проверка доступа к автосалону
        if (!$this->isOwner($currentUser)) {
            if (!$this->hasAccessToDealership($currentUser, $task->dealership_id)) {
                return response()->json([
                    'message' => 'У вас нет доступа к этой задаче'
                ], 403);
            }
        }

        // Проверка статуса
        if ($taskResponse->status !== 'pending_review') {
            return response()->json([
                'message' => 'Этот ответ не требует верификации'
            ], 422);
        }

        // Отклоняем через сервис (удаляет файлы, записывает историю, статус -> 'rejected')
        $this->verificationService->reject($taskResponse, $currentUser, $validated['reason']);

        return response()->json([
            'message' => 'Доказательство отклонено',
            'data' => $task->refresh()
                ->load(['assignments.user', 'responses.user', 'responses.proofs', 'responses.verifier'])
                ->toApiArray()
        ]);
    }
}
