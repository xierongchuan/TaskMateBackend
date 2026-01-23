<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\TimeHelper;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\TaskVerificationHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для верификации доказательств выполнения задач.
 *
 * Централизует логику одобрения/отклонения доказательств
 * и ведет историю верификации.
 */
class TaskVerificationService
{
    public function __construct(
        private readonly TaskProofService $taskProofService
    ) {}

    /**
     * Одобрить доказательство выполнения задачи.
     *
     * @param TaskResponse $response Ответ на задачу
     * @param User $verifier Верификатор (менеджер/владелец)
     * @return TaskResponse Обновленный ответ
     */
    public function approve(TaskResponse $response, User $verifier): TaskResponse
    {
        return DB::transaction(function () use ($response, $verifier) {
            $previousStatus = $response->status;
            $proofCount = $response->proofs()->count();

            $response->update([
                'status' => 'completed',
                'verified_at' => TimeHelper::nowUtc(),
                'verified_by' => $verifier->id,
                'rejection_reason' => null,
            ]);

            $this->recordHistory(
                $response,
                TaskVerificationHistory::ACTION_APPROVED,
                $verifier,
                $previousStatus,
                'completed',
                $proofCount
            );

            return $response->fresh();
        });
    }

    /**
     * Отклонить доказательство выполнения задачи.
     *
     * Для групповых задач с complete_for_all (shared_proofs) отклоняет всех исполнителей.
     * Для остальных задач отклоняет только текущий response.
     *
     * @param TaskResponse $response Ответ на задачу
     * @param User $verifier Верификатор (менеджер/владелец)
     * @param string $reason Причина отклонения
     * @return TaskResponse Обновленный ответ
     */
    public function reject(TaskResponse $response, User $verifier, string $reason): TaskResponse
    {
        return DB::transaction(function () use ($response, $verifier, $reason) {
            $task = $response->task;

            // Проверяем, есть ли у задачи shared_proofs (complete_for_all)
            $hasSharedProofs = $task->sharedProofs()->exists();

            if ($hasSharedProofs) {
                // Групповая задача с complete_for_all → отклонить всех
                $this->rejectAllPendingResponses($task, $verifier, $reason);
            } else {
                // Индивидуальная задача или отдельный response
                $this->rejectSingleResponse($response, $verifier, $reason);
            }

            return $response->fresh();
        });
    }

    /**
     * Отклонить все pending_review responses для групповой задачи.
     *
     * Используется для complete_for_all задач с shared_proofs.
     *
     * @param \App\Models\Task $task Задача
     * @param User $verifier Верификатор
     * @param string $reason Причина отклонения
     */
    private function rejectAllPendingResponses(\App\Models\Task $task, User $verifier, string $reason): void
    {
        // Получаем все pending_review responses
        $pendingResponses = $task->responses()
            ->where('status', 'pending_review')
            ->get();

        foreach ($pendingResponses as $response) {
            $previousStatus = $response->status;
            $proofCount = $response->proofs()->count();

            // Удаляем индивидуальные файлы каждого response
            $this->taskProofService->deleteAllProofs($response);

            // Отклоняем response
            $response->update([
                'status' => 'rejected',
                'verified_at' => null,
                'verified_by' => null,
                'rejection_reason' => $reason,
                'rejection_count' => ($response->rejection_count ?? 0) + 1,
            ]);

            // Записываем в историю
            $this->recordHistory(
                $response,
                TaskVerificationHistory::ACTION_REJECTED,
                $verifier,
                $previousStatus,
                'rejected',
                $proofCount,
                $reason
            );
        }

        // Удаляем shared_proofs ОДИН РАЗ для всей задачи
        if ($task->sharedProofs()->exists()) {
            $this->taskProofService->deleteSharedProofs($task);
        }
    }

    /**
     * Отклонить один response.
     *
     * Используется для индивидуальных задач или когда нет shared_proofs.
     *
     * @param TaskResponse $response Ответ на задачу
     * @param User $verifier Верификатор
     * @param string $reason Причина отклонения
     */
    private function rejectSingleResponse(TaskResponse $response, User $verifier, string $reason): void
    {
        $previousStatus = $response->status;
        $proofCount = $response->proofs()->count();

        // Удаляем все файлы доказательств
        $this->taskProofService->deleteAllProofs($response);

        $response->update([
            'status' => 'rejected',
            'verified_at' => null,
            'verified_by' => null,
            'rejection_reason' => $reason,
            'rejection_count' => ($response->rejection_count ?? 0) + 1,
        ]);

        $this->recordHistory(
            $response,
            TaskVerificationHistory::ACTION_REJECTED,
            $verifier,
            $previousStatus,
            'rejected',
            $proofCount,
            $reason
        );
    }

    /**
     * Записать повторную отправку доказательства.
     *
     * @param TaskResponse $response Ответ на задачу
     * @param User $employee Сотрудник
     */
    public function recordResubmission(TaskResponse $response, User $employee): void
    {
        $this->recordHistory(
            $response,
            TaskVerificationHistory::ACTION_RESUBMITTED,
            $employee,
            'rejected',
            'pending_review',
            $response->proofs()->count()
        );
    }

    /**
     * Записать первоначальную отправку доказательства.
     *
     * @param TaskResponse $response Ответ на задачу
     * @param User $employee Сотрудник
     */
    public function recordSubmission(TaskResponse $response, User $employee): void
    {
        $this->recordHistory(
            $response,
            TaskVerificationHistory::ACTION_SUBMITTED,
            $employee,
            'pending',
            'pending_review',
            $response->proofs()->count()
        );
    }

    /**
     * Записать действие в историю верификации.
     */
    private function recordHistory(
        TaskResponse $response,
        string $action,
        User $performer,
        string $previousStatus,
        string $newStatus,
        int $proofCount,
        ?string $reason = null
    ): void {
        TaskVerificationHistory::create([
            'task_response_id' => $response->id,
            'action' => $action,
            'performed_by' => $performer->id,
            'reason' => $reason,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'proof_count' => $proofCount,
            'created_at' => TimeHelper::nowUtc(),
        ]);
    }
}
