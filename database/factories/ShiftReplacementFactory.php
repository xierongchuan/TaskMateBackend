<?php

namespace Database\Factories;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShiftReplacement>
 */
class ShiftReplacementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'replaced_user_id' => User::factory(),
            'replacing_user_id' => User::factory(),
            'reason' => fake()->sentence(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
