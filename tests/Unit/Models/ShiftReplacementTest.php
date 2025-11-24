<?php

declare(strict_types=1);

use App\Models\ShiftReplacement;
use App\Models\Shift;
use App\Models\User;

describe('ShiftReplacement Model', function () {
    it('belongs to shift', function () {
        $shift = Shift::factory()->create();
        $replacement = ShiftReplacement::factory()->create(['shift_id' => $shift->id]);

        expect($replacement->shift->id)->toBe($shift->id);
    });

    it('belongs to replaced user', function () {
        $user = User::factory()->create();
        $replacement = ShiftReplacement::factory()->create(['replaced_user_id' => $user->id]);

        expect($replacement->replacedUser->id)->toBe($user->id);
    });

    it('belongs to replacing user', function () {
        $user = User::factory()->create();
        $replacement = ShiftReplacement::factory()->create(['replacing_user_id' => $user->id]);

        expect($replacement->replacingUser->id)->toBe($user->id);
    });
});
