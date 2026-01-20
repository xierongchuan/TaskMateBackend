<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\TimeHelper;
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
     * @param TaskResponse $response Ответ на задачу
     * @param User $verifier Верификатор (менеджер/владелец)
     * @param string $reason Причина отклонения
     * @return TaskResponse Обновленный ответ
     */
    public function reject(TaskResponse $response, User $verifier, string $reason): TaskResponse
    {
        return DB::transaction(function () use ($response, $verifier, $reason) {
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

            return $response->fresh();
        });
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
