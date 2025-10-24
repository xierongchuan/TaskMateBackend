<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\Role;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

class UserRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_user_via_public_endpoint(): void
    {
        $userData = [
            'login' => 'testuser',
            'password' => 'TestPass123',
            'full_name' => 'Test User',
            'phone' => '+79991234567',
            'role' => Role::EMPLOYEE->value,
        ];

        $response = $this->postJson('/api/v1/users/create', $userData);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Сотрудник успешно создан'
            ]);

        $this->assertDatabaseHas('users', [
            'login' => 'testuser',
            'full_name' => 'Test User',
            'phone' => '+79991234567',
            'role' => Role::EMPLOYEE->value,
        ]);
    }

    public function test_user_registration_validation_fails_with_invalid_data(): void
    {
        $invalidData = [
            'login' => 'ab', // too short
            'password' => '123', // too short and doesn't match regex
            'full_name' => '', // empty
            'phone' => 'invalid', // invalid format
            'role' => 'invalid_role', // invalid role
        ];

        $response = $this->postJson('/api/v1/users/create', $invalidData);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Ошибка валидации'
            ]);
    }

    public function test_user_registration_fails_with_duplicate_login(): void
    {
        // Create first user
        $userData = [
            'login' => 'testuser',
            'password' => 'TestPass123',
            'full_name' => 'Test User',
            'phone' => '+79991234567',
            'role' => Role::EMPLOYEE->value,
        ];

        $this->postJson('/api/v1/users/create', $userData)->assertStatus(201);

        // Try to create second user with same login
        $duplicateUserData = [
            'login' => 'testuser', // duplicate
            'password' => 'TestPass123',
            'full_name' => 'Another User',
            'phone' => '+79991234568',
            'role' => Role::EMPLOYEE->value,
        ];

        $response = $this->postJson('/api/v1/users/create', $duplicateUserData);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Ошибка валидации'
            ]);
    }
}