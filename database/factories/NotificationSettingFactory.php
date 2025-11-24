<?php

namespace Database\Factories;

use App\Models\AutoDealership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotificationSetting>
 */
class NotificationSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dealership_id' => AutoDealership::factory(),
            'channel_type' => $this->faker->word,
            'is_enabled' => true,
            'notification_time' => $this->faker->time(),
            'notification_day' => $this->faker->dayOfWeek,
            'notification_offset' => $this->faker->numberBetween(1, 60),
        ];
    }
}
