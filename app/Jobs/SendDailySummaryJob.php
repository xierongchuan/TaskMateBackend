<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AutoDealership;
use App\Services\ManagerNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to send daily summary to managers
 * Should be scheduled to run at the end of each day
 */
class SendDailySummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        //
    }

    /**
     * Execute the job
     */
    public function handle(ManagerNotificationService $managerService): void
    {
        try {
            // Get all active dealerships
            $dealerships = AutoDealership::all();

            foreach ($dealerships as $dealership) {
                $managerService->sendDailySummary($dealership->id);
                Log::info("Daily summary sent for dealership #{$dealership->id}");
            }

            Log::info("SendDailySummaryJob completed for {$dealerships->count()} dealerships");
        } catch (\Throwable $e) {
            Log::error('SendDailySummaryJob failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
