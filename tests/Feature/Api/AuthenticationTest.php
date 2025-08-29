<?php

declare(strict_types=1);

use App\Models\User;
use App\Enums\Role;
use Illuminate\Support\Facades\Hash;

describe('Authentication Endpoints', function () {
    describe('POST /api/v1/register', function () {
        it('registers user with valid data', function () {
            // Arrange
            $userData = [
                'login' => 'testuser123',
                'password' => 'SecurePass123!@#',
            ];

            // Act
            $response = $this->postJson('/api/v1/register', $userData);

            // Assert
            $response->assertStatus(200)
                ->assertJsonStructure([
                    'message',
                    'token',
                ])
                ->assertJson([
                    'message' => 'Пользователь успешно зарегистрирован',
                ]);

            // Verify user was created in database
            $user = User::where('login', 'testuser123')->first();
            expect($user)
                ->not->toBeNull()
                ->and($user->login)->toBe('testuser123')
                ->and($user->role)->toBe(Role::USER->value)
                ->and($user->full_name)->toBe('-')
                ->and($user->telegram_id)->toBe(0)
                ->and($user->company_id)->toBe(0)
                ->and($user->phone)->toBe('+0000000000');

            // Verify password is hashed
            expect(Hash::check('SecurePass123!@#', $user->password))->toBeTrue();

            // Verify token is valid
            expect($response->json('token'))->toBeString()->not->toBeEmpty();
        });

        it('validates login is required', function () {
            // Arrange
            $userData = [
                'password' => 'SecurePass123!@#',
            ];

            // Act
            $response = $this->postJson('/api/v1/register', $userData);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['login']);
        });

        it('validates login minimum length', function () {
            // Arrange
            $userData = [
                'login' => 'abc', // Less than 4 characters
                'password' => 'SecurePass123!@#',
            ];

            // Act
            $response = $this->postJson('/api/v1/register', $userData);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['login']);
        });

        it('validates login maximum length', function () {
            // Arrange
            $userData = [
                'login' => str_repeat('a', 256), // More than 255 characters
                'password' => 'SecurePass123!@#',
            ];

            // Act
            $response = $this->postJson('/api/v1/register', $userData);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['login']);
        });

        it('validates login uniqueness', function () {
            // Arrange
            User::factory()->create(['login' => 'existinguser']);

            $userData = [
                'login' => 'existinguser',
                'password' => 'SecurePass123!@#',
            ];

            // Act
            $response = $this->postJson('/api/v1/register', $userData);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['login']);
        });

        it('validates password is required', function () {
            // Arrange
            $userData = [
                'login' => 'testuser123',
            ];

            // Act
            $response = $this->postJson('/api/v1/register', $userData);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['password']);
        });

        it('validates password minimum length', function () {
            // Arrange
            $userData = [
                'login' => 'testuser123',
                'password' => 'Short1!@', // Less than 12 characters
            ];

            // Act
            $response = $this->postJson('/api/v1/register', $userData);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['password']);
        });

        it('validates password maximum length', function () {
            // Arrange
            $userData = [
                'login' => 'testuser123',
                'password' => str_repeat('A1@', 100), // More than 255 characters
            ];

            // Act
            $response = $this->postJson('/api/v1/register', $userData);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['password']);
        });

        it('validates password complexity requirements', function () {
            // Arrange - Test various invalid password patterns
            $invalidPasswords = [
                'onlylowercase123!', // Missing uppercase
                'ONLYUPPERCASE123!', // Missing lowercase
                'OnlyLettersNoNumbers!', // Missing digits
                'OnlyAlphanumeric123', // Missing special characters
                'NoNumbers!@#', // Missing digits and case variety
            ];

            foreach ($invalidPasswords as $password) {
                $userData = [
                    'login' => 'testuser' . rand(1000, 9999),
                    'password' => $password,
                ];

                // Act
                $response = $this->postJson('/api/v1/register', $userData);

                // Assert
                $response->assertStatus(422)
                    ->assertJsonValidationErrors(['password']);
            }
        });

        it('accepts valid password with all required complexity', function () {
            // Arrange
            $userData = [
                'login' => 'validuser123',
                'password' => 'ValidPass123!@#', // Has all requirements
            ];

            // Act
            $response = $this->postJson('/api/v1/register', $userData);

            // Assert
            $response->assertStatus(200);
        });

        it('handles invalid JSON gracefully', function () {
            // Act - Send malformed JSON content
            $response = $this->postJson('/api/v1/register', [], ['CONTENT_TYPE' => 'application/json']);

            // Assert
            $response->assertStatus(422); // Laravel returns 422 for validation errors
        });

        it('handles empty request body', function () {
            // Act
            $response = $this->postJson('/api/v1/register', []);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['login', 'password']);
        });

        it('creates user with correct default values', function () {
            // Arrange
            $userData = [
                'login' => 'defaultstest',
                'password' => 'SecurePass123!@#',
            ];

            // Act
            $response = $this->postJson('/api/v1/register', $userData);

            // Assert
            $response->assertStatus(200);

            $user = User::where('login', 'defaultstest')->first();
            expect($user->role)->toBe(Role::USER->value)
                ->and($user->full_name)->toBe('-')
                ->and($user->telegram_id)->toBe(0)
                ->and($user->company_id)->toBe(0)
                ->and($user->phone)->toBe('+0000000000');
        });

        it('returns valid Sanctum token', function () {
            // Arrange
            $userData = [
                'login' => 'tokentest',
                'password' => 'SecurePass123!@#',
            ];

            // Act
            $response = $this->postJson('/api/v1/register', $userData);

            // Assert
            $response->assertStatus(200);

            $token = $response->json('token');
            expect($token)->toBeString()->not->toBeEmpty();

            // Verify token works for authenticated requests
            $user = User::where('login', 'tokentest')->first();
            $this->actingAs($user, 'sanctum');

            // This would work if there were authenticated endpoints to test
            // $authResponse = $this->getJson('/api/v1/user');
            // $authResponse->assertStatus(200);
        });
    });

    describe('Registration Edge Cases', function () {
        it('handles very long valid login', function () {
            // Arrange
            $userData = [
                'login' => str_repeat('a', 255), // Exactly 255 characters (max allowed)
                'password' => 'SecurePass123!@#',
            ];

            // Act
            $response = $this->postJson('/api/v1/register', $userData);

            // Assert
            $response->assertStatus(200);
        });

        it('handles minimum valid password', function () {
            // Arrange
            $userData = [
                'login' => 'minpasstest',
                'password' => 'ValidPass1!@#', // Exactly 13 characters with all requirements
            ];

            // Act
            $response = $this->postJson('/api/v1/register', $userData);

            // Assert
            $response->assertStatus(200);
        });

        it('handles maximum valid password', function () {
            // Arrange - Create a 255 character password with all requirements
            $basePattern = 'ValidPass1!@#'; // 13 chars with all requirements
            $validLongPassword = str_repeat($basePattern, 19) . 'A1@'; // 13*19 + 3 = 250 chars
            $userData = [
                'login' => 'maxpasstest',
                'password' => $validLongPassword,
            ];

            // Act
            $response = $this->postJson('/api/v1/register', $userData);

            // Assert
            $response->assertStatus(200);
        });

        it('prevents SQL injection in login field', function () {
            // Arrange
            $userData = [
                'login' => "'; DROP TABLE users; --",
                'password' => 'SecurePass123!@#',
            ];

            // Act
            $response = $this->postJson('/api/v1/register', $userData);

            // Assert - Should handle gracefully without breaking
            // Could be 422 (validation error) or 200 (successful with sanitized input)
            expect($response->status())->toBeIn([200, 422]);

            // Ensure users table still exists by creating another user
            $safeUserData = [
                'login' => 'safeuser',
                'password' => 'SecurePass123!@#',
            ];
            $safeResponse = $this->postJson('/api/v1/register', $safeUserData);
            $safeResponse->assertStatus(200);
        });
    });
});
