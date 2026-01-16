<?php

declare(strict_types=1);

use App\Models\Shift;
use App\Models\User;
use App\Models\AutoDealership;

describe('Shift Model', function () {
    it('belongs to user', function () {
        $user = User::factory()->create();
        $shift = Shift::factory()->create(['user_id' => $user->id]);

        expect($shift->user->id)->toBe($user->id);
    });

    it('belongs to dealership', function () {
        $dealership = AutoDealership::factory()->create();
        $shift = Shift::factory()->create(['dealership_id' => $dealership->id]);

        expect($shift->dealership->id)->toBe($dealership->id);
    });

    it('has replacement', function () {
        $shift = Shift::factory()->create();
        // Assuming factory or manual creation of replacement
        // Since ShiftReplacementFactory exists now
        $replacement = \App\Models\ShiftReplacement::factory()->create(['shift_id' => $shift->id]);

        expect($shift->replacement->id)->toBe($replacement->id);
    });
});
