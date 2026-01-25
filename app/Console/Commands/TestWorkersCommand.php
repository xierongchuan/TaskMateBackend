<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CheckOverdueTasksJob;
use App\Jobs\CheckUnrespondedTasksJob;
use App\Jobs\CheckUpcomingDeadlinesJob;
use App\Jobs\SendScheduledTasksJob;
use Illuminate\Console\Command;

class TestWorkersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workers:test {type=all : Type of worker to test (overdue|upcoming|unresponded|scheduled|all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test notification workers manually';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->argument('type');

        $this->info("Testing worker: {$type}");
        $this->info("Current time: " . now()->format('Y-m-d H:i:s T'));

        switch ($type) {
            case 'overdue':
                $this->info('Dispatching CheckOverdueTasksJob...');
                CheckOverdueTasksJob::dispatch();
                break;

            case 'upcoming':
                $this->info('Dispatching CheckUpcomingDeadlinesJob...');
                CheckUpcomingDeadlinesJob::dispatch();
                break;

            case 'unresponded':
                $this->info('Dispatching CheckUnrespondedTasksJob...');
                CheckUnrespondedTasksJob::dispatch();
                break;

            case 'scheduled':
                $this->info('Dispatching SendScheduledTasksJob...');
                SendScheduledTasksJob::dispatch();
                break;

            case 'all':
                $this->info('Dispatching all notification workers...');
                CheckOverdueTasksJob::dispatch();
                CheckUpcomingDeadlinesJob::dispatch();
                CheckUnrespondedTasksJob::dispatch();
                SendScheduledTasksJob::dispatch();
                break;

            default:
                $this->error("Unknown worker type: {$type}");
                $this->info('Available types: overdue, upcoming, unresponded, scheduled, all');
                return 1;
        }

        $this->info('Workers dispatched successfully!');
        $this->info('Check the logs for worker execution details.');

        return Command::SUCCESS;
    }
}