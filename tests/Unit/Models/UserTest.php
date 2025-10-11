<?php

declare(strict_types=1);

use App\Models\User;
use App\Enums\Role;
use Illuminate\Support\Facades\Hash;

describe('User Model', function () {
    it('can create a user with all fields', function () {
        // Arrange & Act
        $user = User::create([
            'login' => 'testuser123',
            'full_name' => 'Test User Full Name',
            'telegram_id' => 123456789,
            'phone' => '+998901234567',
            'role' => Role::EMPLOYEE->value,
            'company_id' => 1,
            'password' => Hash::make('password123'),
        ]);

        // Assert
        expect($user)
            ->toBeInstanceOf(User::class)
            ->and($user->login)->toBe('testuser123')
            ->and($user->full_name)->toBe('Test User Full Name')
            ->and($user->telegram_id)->toBe(123456789)
            ->and($user->phone)->toBe('+998901234567')
            ->and($user->role)->toBe(Role::EMPLOYEE->value)
            ->and($user->company_id)->toBe(1)
            ->and($user->exists)->toBeTrue();
    });

    it('can create user with minimum required fields', function () {
        // Arrange & Act
        $user = User::create([
            'login' => 'minuser',
            'role' => Role::EMPLOYEE->value,
        ]);

        // Assert
        expect($user)
            ->toBeInstanceOf(User::class)
            ->and($user->login)->toBe('minuser')
            ->and($user->role)->toBe(Role::EMPLOYEE->value)
            ->and($user->exists)->toBeTrue();
    });

    it('hides password in array representation', function () {
        // Arrange
        $user = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);

        // Act
        $userArray = $user->toArray();

        // Assert
        expect($userArray)->not->toHaveKey('password');
    });

    it('can create users with different roles', function () {
        // Arrange & Act
        $owner = User::create([
            'login' => 'owner1',
            'role' => Role::OWNER->value,
            'company_id' => 1,
        ]);

        $manager = User::create([
            'login' => 'manager1',
            'role' => Role::MANAGER->value,
            'company_id' => 1,
        ]);

        $employee = User::create([
            'login' => 'employee1',
            'role' => Role::EMPLOYEE->value,
            'company_id' => 1,
        ]);

        // Assert
        expect($owner->role)->toBe(Role::OWNER->value)
            ->and($manager->role)->toBe(Role::MANAGER->value)
            ->and($employee->role)->toBe(Role::EMPLOYEE->value);
    });

    it('can query users by role and company', function () {
        // Arrange
        User::factory()->create([
            'role' => Role::MANAGER->value,
            'company_id' => 1,
        ]);

        User::factory()->create([
            'role' => Role::MANAGER->value,
            'company_id' => 2,
        ]);

        User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'company_id' => 1,
        ]);

        // Act
        $managersCompany1 = User::where('role', Role::MANAGER->value)
            ->where('company_id', 1)
            ->get();

        // Assert
        expect($managersCompany1)->toHaveCount(1)
            ->and($managersCompany1->first()->role)->toBe(Role::MANAGER->value)
            ->and($managersCompany1->first()->company_id)->toBe(1);
    });

    it('can find user by telegram_id', function () {
        // Arrange
        $telegramId = 987654321;
        $user = User::factory()->create([
            'telegram_id' => $telegramId,
        ]);

        // Act
        $foundUser = User::where('telegram_id', $telegramId)->first();

        // Assert
        expect($foundUser)
            ->not->toBeNull()
            ->and($foundUser->id)->toBe($user->id)
            ->and($foundUser->telegram_id)->toBe($telegramId);
    });

    it('stores and retrieves phone numbers correctly', function () {
        // Arrange
        $phoneNumbers = [
            '+998901234567',
            '+1234567890',
            '+7123456789',
        ];

        // Act & Assert
        foreach ($phoneNumbers as $phone) {
            $user = User::factory()->create(['phone' => $phone]);
            expect($user->phone)->toBe($phone);
        }
    });

    it('can update user fields', function () {
        // Arrange
        $user = User::factory()->create([
            'full_name' => 'Original Name',
            'phone' => '+998901111111',
        ]);

        // Act
        $user->update([
            'full_name' => 'Updated Name',
            'phone' => '+998902222222',
        ]);

        // Assert
        expect($user->fresh())
            ->full_name->toBe('Updated Name')
            ->phone->toBe('+998902222222');
    });
});
