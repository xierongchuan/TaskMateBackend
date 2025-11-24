<?php

declare(strict_types=1);

use App\Services\ManagerNotificationService;
use App\Services\TelegramNotificationService;
use App\Models\User;
use App\Models\Task;
use App\Models\AutoDealership;

describe('ManagerNotificationService', function () {
    beforeEach(function () {
        $this->bot = Mockery::mock(\SergiX44\Nutgram\Nutgram::class);
        $this->service = new ManagerNotificationService($this->bot);
    });

    it('notifies managers about overdue task', function () {
        $dealership = AutoDealership::factory()->create();
        $manager = User::factory()->create(['role' => 'manager', 'dealership_id' => $dealership->id, 'telegram_id' => '12345']);
        $task = Task::factory()->create(['dealership_id' => $dealership->id, 'title' => 'Overdue Task']);
        $employee = User::factory()->create(['dealership_id' => $dealership->id]);

        $this->bot->shouldReceive('sendMessage')
            ->andReturn(null);

        $this->service->notifyAboutOverdueTask($task, $employee);
    });
});
