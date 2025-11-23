<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealership_id')
                ->constrained('auto_dealerships')
                ->onDelete('cascade');
            $table->string('channel_type', 50); // e.g., 'task_assigned', 'task_overdue'
            $table->boolean('is_enabled')->default(true);
            $table->time('notification_time')->nullable(); // For scheduled notifications
            $table->string('notification_day')->nullable(); // For weekly reports (e.g., 'monday')
            $table->timestamps();

            // Ensure one setting per channel per dealership
            $table->unique(['dealership_id', 'channel_type']);
        });

        // Create default settings for existing dealerships
        DB::statement("
            INSERT INTO notification_settings (dealership_id, channel_type, is_enabled, notification_time, created_at, updated_at)
            SELECT
                id as dealership_id,
                channel_type,
                true as is_enabled,
                CASE
                    WHEN channel_type = 'daily_summary' THEN '20:00:00'::time
                    WHEN channel_type = 'weekly_report' THEN '09:00:00'::time
                    ELSE NULL
                END as notification_time,
                NOW() as created_at,
                NOW() as updated_at
            FROM auto_dealerships
            CROSS JOIN (
                SELECT 'task_assigned' as channel_type UNION ALL
                SELECT 'task_deadline_30min' UNION ALL
                SELECT 'task_overdue' UNION ALL
                SELECT 'task_hour_late' UNION ALL
                SELECT 'shift_late' UNION ALL
                SELECT 'task_postponed' UNION ALL
                SELECT 'shift_replacement' UNION ALL
                SELECT 'daily_summary' UNION ALL
                SELECT 'weekly_report'
            ) AS channels
        ");

        // Set weekly report day to Monday
        DB::statement("
            UPDATE notification_settings
            SET notification_day = 'monday'
            WHERE channel_type = 'weekly_report'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
