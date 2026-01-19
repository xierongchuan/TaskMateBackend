<?php

declare(strict_types=1);

use App\Services\ShiftService;
use App\Services\SettingsService;
use App\Models\User;
use App\Models\Shift;
use App\Models\AutoDealership;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('ShiftService', function () {
    beforeEach(function () {
        $this->settingsService = Mockery::mock(SettingsService::class);
        $this->service = new ShiftService($this->settingsService);
        Storage::fake('public');
    });

    it('opens a shift', function () {
        $dealership = AutoDealership::factory()->create();
        $user = User::factory()->create(['dealership_id' => $dealership->id]);
        $photo = UploadedFile::fake()->image('photo.jpg');

        $this->settingsService->shouldReceive('getShiftStartTime')->andReturn('09:00');
        $this->settingsService->shouldReceive('getShiftEndTime')->andReturn('18:00');
        $this->settingsService->shouldReceive('getLateTolerance')->andReturn(15);

        $shift = $this->service->openShift($user, $photo);

        expect($shift)->toBeInstanceOf(Shift::class)
            ->and($shift->status)->toBe('open');
    });

    it('closes a shift', function () {
        $dealership = AutoDealership::factory()->create();
        $user = User::factory()->create(['dealership_id' => $dealership->id]);
        $shift = Shift::factory()->create([
            'user_id' => $user->id,
            'dealership_id' => $dealership->id,
            'status' => 'open',
        ]);
        $photo = UploadedFile::fake()->image('closing.jpg');

        $closedShift = $this->service->closeShift($shift, $photo);

        expect($closedShift->status)->toBe('closed')
            ->and($closedShift->shift_end)->not->toBeNull();
    });
});
