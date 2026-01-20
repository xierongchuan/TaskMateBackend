<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Trait for automatic audit logging of model changes.
 *
 * Usage:
 * ```php
 * class Task extends Model
 * {
 *     use Auditable;
 * }
 * ```
 */
trait Auditable
{
    /**
     * Boot the auditable trait for a model.
     */
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            self::logAudit($model, 'created', $model->toArray());
        });

        static::updated(function (Model $model) {
            $changes = $model->getChanges();
            // Remove updated_at from changes since it's automatic
            unset($changes['updated_at']);

            if (!empty($changes)) {
                self::logAudit($model, 'updated', [
                    'old' => array_intersect_key($model->getOriginal(), $changes),
                    'new' => $changes,
                ]);
            }
        });

        static::deleted(function (Model $model) {
            self::logAudit($model, 'deleted', $model->toArray());
        });
    }

    /**
     * Log an audit entry for the model.
     *
     * @param Model $model The model instance
     * @param string $action The action (created, updated, deleted)
     * @param array<string, mixed> $payload The data to log
     */
    protected static function logAudit(Model $model, string $action, array $payload): void
    {
        try {
            AuditLog::create([
                'table_name' => $model->getTable(),
                'record_id' => $model->getKey(),
                'actor_id' => auth()->id(),
                'action' => $action,
                'payload' => $payload,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Silent fail - don't break the main operation
            \Log::warning('Audit log failed: ' . $e->getMessage());
        }
    }

    /**
     * Get audit history for this model.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AuditLog>
     */
    public function auditHistory(): \Illuminate\Database\Eloquent\Collection
    {
        return AuditLog::where('table_name', $this->getTable())
            ->where('record_id', $this->getKey())
            ->orderByDesc('created_at')
            ->get();
    }
}
