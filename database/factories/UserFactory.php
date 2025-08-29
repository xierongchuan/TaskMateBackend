<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'login' => fake()->unique()->userName(),
            'full_name' => fake()->name(),
            'telegram_id' => fake()->randomNumber(9),
            'phone' => fake()->phoneNumber(),
            'role' => fake()->randomElement([Role::USER->value, Role::DIRECTOR->value, Role::ACCOUNTANT->value]),
            'company_id' => fake()->numberBetween(1, 10),
            'password' => Hash::make('password123'),
        ];
    }

    /**
     * Indicate that the user should have a specific role.
     */
    public function role(Role $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => $role->value,
        ]);
    }

    /**
     * Indicate that the user should belong to a specific company.
     */
    public function company(int $companyId): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $companyId,
        ]);
    }

    /**
     * Indicate that the user should have a specific telegram ID.
     */
    public function telegramId(int $telegramId): static
    {
        return $this->state(fn (array $attributes) => [
            'telegram_id' => $telegramId,
        ]);
    }
}
