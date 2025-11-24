<?php

declare(strict_types=1);

use App\Services\TaskNotificationService;
use SergiX44\Nutgram\Nutgram;

describe('TaskNotificationService', function () {
    it('can be instantiated', function () {
        $bot = Mockery::mock(Nutgram::class);
        $service = new TaskNotificationService($bot);

        expect($service)->toBeInstanceOf(TaskNotificationService::class);
    });
});
