<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AutoDealership;
use App\Models\TaskGenerator;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskGenerator>
 */
class TaskGeneratorFactory extends Factory
{
    protected $model = TaskGenerator::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'creator_id' => User::factory(),
            'dealership_id' => AutoDealership::factory(),
            'recurrence' => fake()->randomElement(['daily', 'weekly', 'monthly']),
            'recurrence_time' => '09:00',
            'deadline_time' => '18:00',
            'recurrence_day_of_week' => fake()->optional()->numberBetween(1, 7),
            'recurrence_day_of_month' => fake()->optional()->numberBetween(1, 28),
            'start_date' => Carbon::today(),
            'end_date' => null,
            'task_type' => fake()->randomElement(['individual', 'group']),
            'response_type' => fake()->randomElement(['acknowledge', 'complete']),
            'priority' => fake()->randomElement(['low', 'medium', 'high']),
            'tags' => [],
            'notification_settings' => null,
            'is_active' => true,
            'last_generated_at' => null,
        ];
    }

    /**
     * Indicate that the generator is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Set recurrence to daily.
     */
    public function daily(): static
    {
        return $this->state(fn (array $attributes) => [
            'recurrence' => 'daily',
            'recurrence_day_of_week' => null,
            'recurrence_day_of_month' => null,
        ]);
    }

    /**
     * Set recurrence to weekly.
     */
    public function weekly(int $dayOfWeek = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'recurrence' => 'weekly',
            'recurrence_day_of_week' => $dayOfWeek,
            'recurrence_day_of_month' => null,
        ]);
    }

    /**
     * Set recurrence to monthly.
     */
    public function monthly(int $dayOfMonth = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'recurrence' => 'monthly',
            'recurrence_day_of_week' => null,
            'recurrence_day_of_month' => $dayOfMonth,
        ]);
    }
}
