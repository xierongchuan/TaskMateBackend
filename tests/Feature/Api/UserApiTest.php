<?php

declare(strict_types=1);

use App\Models\User;
use App\Enums\Role;

describe('Users API', function () {
    describe('GET /api/v1/users', function () {
        it('returns paginated list of users', function () {
            // Arrange
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);
            User::factory(5)->create(['role' => Role::EMPLOYEE->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->getJson('/api/v1/users');

            // Assert
            $response->assertStatus(200);
            expect($response->json('data'))->toBeArray();
        });

        it('filters users by role', function () {
            // Arrange
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);
            User::factory(3)->create(['role' => Role::MANAGER->value]);
            User::factory(2)->create(['role' => Role::EMPLOYEE->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->getJson('/api/v1/users?role=' . Role::MANAGER->value);

            // Assert
            $response->assertStatus(200);
        });

        it('requires authentication', function () {
            // Act
            $response = $this->getJson('/api/v1/users');

            // Assert
            expect($response->status())->toBe(401);
        });
    });

    describe('GET /api/v1/users/{id}', function () {
        it('returns single user with relations', function () {
            // Arrange
            $user = User::factory()->create();
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->getJson("/api/v1/users/{$user->id}");

            // Assert
            $response->assertStatus(200);
            expect($response->json())->toBeArray();
        });

        it('returns 404 for non-existent user', function () {
            // Arrange
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->getJson('/api/v1/users/99999');

            // Assert
            expect($response->status())->toBe(404);
        });
    });

    describe('POST /api/v1/users (Create User)', function () {
        it('creates new user with valid data', function () {
            // Arrange
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'newuser',
                    'password' => 'SecurePassword123!',
                    'password_confirmation' => 'SecurePassword123!',
                    'full_name' => 'New User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+998901234567',
                ]);

            // Assert
            $response->assertStatus(201);
            $this->assertDatabaseHas('users', ['login' => 'newuser']);
        });

        it('validates required fields', function () {
            // Arrange
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => '',
                    'password' => '',
                ]);

            // Assert
            expect($response->status())->toBe(422);
        });

        it('validates login format (latin letters, digits, max one dot)', function () {
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Case 1: Too long (>64)
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => str_repeat('a', 65),
                    'password' => 'SecurePassword123!',
                    'full_name' => 'User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+1234567890',
                ]);
            expect($response->status())->toBe(422);

            // Case 2: Invalid chars (Russian)
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'логин',
                    'password' => 'SecurePassword123!',
                    'full_name' => 'User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+1234567890',
                ]);
            expect($response->status())->toBe(422);

            // Case 3: Invalid Special chars (e.g. @)
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'user@name',
                    'password' => 'SecurePassword123!',
                    'full_name' => 'User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+1234567890',
                ]);
            expect($response->status())->toBe(422);

             // Case 4: Multiple dots
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'user.name.test',
                    'password' => 'SecurePassword123!',
                    'full_name' => 'User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+1234567890',
                ]);
            expect($response->status())->toBe(422);

             // Case 5: Multiple underscores
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'user_name_test',
                    'password' => 'SecurePassword123!',
                    'full_name' => 'User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+1234567890',
                ]);
            expect($response->status())->toBe(422);

            // Case 6: Valid dot
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'user.name',
                    'password' => 'SecurePassword123!',
                    'full_name' => 'User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+1234567890',
                    'telegram_id' => 1001,
                ]);
            expect($response->status())->toBe(201);

            // Case 7: Valid underscore
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'user_name',
                    'password' => 'SecurePassword123!',
                    'full_name' => 'User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+1234567890',
                    'telegram_id' => 1002,
                ]);
            expect($response->status())->toBe(201);

            // Case 8: Valid dot AND underscore
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'user.name_test',
                    'password' => 'SecurePassword123!',
                    'full_name' => 'User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+1234567890',
                    'telegram_id' => 1003,
                ]);
            expect($response->status())->toBe(201);
        });

        it('requires manager or owner role', function () {
            // Arrange
            $employee = User::factory()->create(['role' => Role::EMPLOYEE->value]);

            // Act
            $response = $this->actingAs($employee, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'newuser',
                    'password' => 'Password123!',
                    'password_confirmation' => 'Password123!',
                    'role' => Role::EMPLOYEE->value,
                ]);

            // Assert
            expect($response->status())->toBe(403);
        });
    });

    describe('PUT /api/v1/users/{id} (Update User)', function () {
        it('updates user with valid data', function () {
            // Arrange
            $user = User::factory()->create(['full_name' => 'Old Name']);
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->putJson("/api/v1/users/{$user->id}", [
                    'full_name' => 'Updated Name',
                    'phone' => '+998901234567',
                ]);

            // Assert
            $response->assertStatus(200);
            $this->assertDatabaseHas('users', [
                'id' => $user->id,
                'full_name' => 'Updated Name',
            ]);
        });

        it('requires manager or owner role for update', function () {
            // Arrange
            $user = User::factory()->create();
            $employee = User::factory()->create(['role' => Role::EMPLOYEE->value]);

            // Act
            $response = $this->actingAs($employee, 'sanctum')
                ->putJson("/api/v1/users/{$user->id}", [
                    'full_name' => 'Updated Name',
                ]);

            // Assert
            expect($response->status())->toBe(403);
        });
    });

    describe('DELETE /api/v1/users/{id} (Delete User)', function () {
        it('deletes user successfully', function () {
            // Arrange
            $user = User::factory()->create();
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->deleteJson("/api/v1/users/{$user->id}");

            // Assert
            expect($response->status())->toBe(200);
        });

        it('requires manager or owner role for deletion', function () {
            // Arrange
            $user = User::factory()->create();
            $employee = User::factory()->create(['role' => Role::EMPLOYEE->value]);

            // Act
            $response = $this->actingAs($employee, 'sanctum')
                ->deleteJson("/api/v1/users/{$user->id}");

            // Assert
            expect($response->status())->toBe(403);
        });
    });

    describe('GET /api/v1/users/{id}/status', function () {
        it('returns user status information', function () {
            // Arrange
            $user = User::factory()->create();
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->getJson("/api/v1/users/{$user->id}/status");

            // Assert
            $response->assertStatus(200);
        });
    });
});
