<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExpenseApproval;
use App\Models\ExpenseRequest;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Enums\ExpenseStatus;
use Throwable;

class ExpenseService
{
    /** Director approves a request */
    public function directorApprove(int $requestId, int $directorId, ?string $comment = null): void
    {
        DB::transaction(function () use ($requestId, $directorId, $comment) {
            $req = ExpenseRequest::where('id', $requestId)->lockForUpdate()->firstOrFail();

            if ($req->status !== 'pending_director') {
                throw new \RuntimeException('Неверный статус заявки для подтверждения');
            }

            ExpenseApproval::create([
                'expense_request_id' => $req->id,
                'actor_id' => $directorId,
                'actor_role' => 'director',
                'action' => 'approved',
                'comment' => $comment,
                'created_at' => now(),
            ]);

            $oldStatus = $req->status;

            $req->update([
                'status' => 'director_approved',
                'director_id' => $directorId,
                'director_comment' => $comment,
                'director_approved_at' => now(),
            ]);

            AuditLog::create([
                'table_name' => 'expense_requests',
                'record_id' => $req->id,
                'actor_id' => $directorId,
                'action' => 'director_approved',
                'payload' => ['old_status' => $oldStatus, 'new_status' => 'director_approved'],
                'created_at' => now(),
            ]);
        });
    }

    /** Director declines a request */
    public function directorDecline(int $requestId, int $directorId, ?string $comment = null): void
    {
        DB::transaction(function () use ($requestId, $directorId, $comment) {
            $req = ExpenseRequest::where('id', $requestId)->lockForUpdate()->firstOrFail();

            if ($req->status !== 'pending_director') {
                throw new \RuntimeException('Неверный статус заявки для отклонения');
            }

            ExpenseApproval::create([
                'expense_request_id' => $req->id,
                'actor_id' => $directorId,
                'actor_role' => 'director',
                'action' => 'declined',
                'comment' => $comment,
                'created_at' => now(),
            ]);

            $oldStatus = $req->status;

            $req->update([
                'status' => 'director_declined',
                'director_id' => $directorId,
                'director_comment' => $comment,
                'director_approved_at' => now(),
            ]);

            AuditLog::create([
                'table_name' => 'expense_requests',
                'record_id' => $req->id,
                'actor_id' => $directorId,
                'action' => 'director_declined',
                'payload' => ['reason' => $comment],
                'created_at' => now(),
            ]);
        });
    }

    /** Accountant issues funds */
    public function accountantIssue(int $requestId, int $accountantId, ?string $comment = null): void
    {
        DB::transaction(function () use ($requestId, $accountantId, $comment) {
            $req = ExpenseRequest::where('id', $requestId)->lockForUpdate()->firstOrFail();

            if ($req->status !== 'director_approved') {
                throw new \RuntimeException('Заявка не одобрена директором');
            }

            ExpenseApproval::create([
                'expense_request_id' => $req->id,
                'actor_id' => $accountantId,
                'actor_role' => 'accountant',
                'action' => 'issued',
                'comment' => $comment,
                'created_at' => now(),
            ]);

            $req->update([
                'status' => 'issued',
                'accountant_id' => $accountantId,
                'issued_at' => now(),
            ]);

            AuditLog::create([
                'table_name' => 'expense_requests',
                'record_id' => $req->id,
                'actor_id' => $accountantId,
                'action' => 'issued',
                'payload' => ['issued_by' => $accountantId],
                'created_at' => now(),
            ]);
        });
    }

    /** Create request (with audit) */
    public static function createRequest(
        int $requesterId,
        string $description,
        float $amount,
        string $currency = 'UZS'
    ): int {
        return DB::transaction(function () use ($requesterId, $description, $amount, $currency) {
            $req = ExpenseRequest::create([
                'requester_id' => $requesterId,
                'description' => $description,
                'amount' => $amount,
                'currency' => $currency,
                'status' => ExpenseStatus::PENDING_DIRECTOR->value,
            ]);

            AuditLog::create([
                'table_name' => 'expense_requests',
                'record_id' => $req->id,
                'actor_id' => $requesterId,
                'action' => 'insert',
                'payload' => ['amount' => $amount, 'currency' => $currency],
                'created_at' => now(),
            ]);

            return $req->id;
        });
    }

    /** Delete request (with audit) */
    public function deleteRequest(int $requestId, int $actorId, ?string $reason = null): void
    {
        DB::transaction(function () use ($requestId, $actorId, $reason) {
            $req = ExpenseRequest::where('id', $requestId)->lockForUpdate()->firstOrFail();

            $req->delete(); // ON DELETE CASCADE должен удалить связанные approvals

            AuditLog::create([
                'table_name' => 'expense_requests',
                'record_id' => $requestId,
                'actor_id' => $actorId,
                'action' => 'delete',
                'payload' => ['reason' => $reason],
                'created_at' => now(),
            ]);
        });
    }

    /** Mass director approve */
    public function massDirectorApprove(array $requestIds, int $directorId, ?string $comment = null): void
    {
        if (empty($requestIds)) {
            return;
        }

        DB::transaction(function () use ($requestIds, $directorId, $comment) {
            $requests = ExpenseRequest::whereIn('id', $requestIds)->lockForUpdate()->get();

            // validate statuses - optional, but recommended
            foreach ($requests as $r) {
                if ($r->status !== 'pending_director') {
                    throw new \RuntimeException("Request {$r->id} has invalid status");
                }
            }

            $now = now();

            // bulk insert approvals
            $inserts = [];
            foreach ($requests as $r) {
                $inserts[] = [
                    'expense_request_id' => $r->id,
                    'actor_id' => $directorId,
                    'actor_role' => 'director',
                    'action' => 'approved',
                    'comment' => $comment,
                    'created_at' => $now,
                ];
            }
            ExpenseApproval::insert($inserts);

            // bulk update requests
            ExpenseRequest::whereIn('id', $requestIds)->update([
                'status' => 'director_approved',
                'director_id' => $directorId,
                'director_comment' => $comment,
                'director_approved_at' => $now,
                'updated_at' => $now,
            ]);

            // audit logs (per row) - keep concise
            $auditInserts = [];
            foreach ($requests as $r) {
                $auditInserts[] = [
                    'table_name' => 'expense_requests',
                    'record_id' => $r->id,
                    'actor_id' => $directorId,
                    'action' => 'director_approved',
                    'payload' => json_encode(['old_status' => 'pending_director', 'new_status' => 'director_approved']),
                    'created_at' => $now,
                ];
            }
            DB::table('audit_logs')->insert($auditInserts);
        });
    }
}
