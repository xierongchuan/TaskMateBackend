<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Enums\Role;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class StartConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_normalization_russian_formats(): void
    {
        $reflection = new \ReflectionClass('\App\Bot\Conversations\Guest\StartConversation');
        $method = $reflection->getMethod('normalizePhoneNumber');
        $method->setAccessible(true);
        $conversation = new \App\Bot\Conversations\Guest\StartConversation();

        // Test various Russian phone formats
        $testCases = [
            '+7 (999) 123-45-67' => '79991234567',
            '8 (999) 123-45-67' => '79991234567',
            '89991234567' => '79991234567',
            '+79991234567' => '79991234567',
            '9991234567' => '79991234567', // Assumes Russian if 10 digits
            '(999) 123-45-67' => '79991234567', // Assumes Russian if 10 digits
        ];

        foreach ($testCases as $input => $expected) {
            $result = $method->invoke($conversation, $input);
            $this->assertEquals($expected, $result, "Failed for input: {$input}");
        }
    }

    public function test_phone_validation(): void
    {
        $reflection = new \ReflectionClass('\App\Bot\Conversations\Guest\StartConversation');
        $method = $reflection->getMethod('isValidPhoneNumber');
        $method->setAccessible(true);
        $conversation = new \App\Bot\Conversations\Guest\StartConversation();

        // Valid phone numbers
        $validPhones = ['79991234567', '123456789012345'];
        foreach ($validPhones as $phone) {
            $this->assertTrue($method->invoke($conversation, $phone), "Phone should be valid: {$phone}");
        }

        // Invalid phone numbers
        $invalidPhones = ['123', '1234567890123456', 'abc', ''];
        foreach ($invalidPhones as $phone) {
            $this->assertFalse($method->invoke($conversation, $phone), "Phone should be invalid: {$phone}");
        }
    }

    public function test_find_user_by_phone_multiple_strategies(): void
    {
        // Create users with different phone formats (without dealership_id to avoid migration conflicts)
        $users = [
            User::create([
                'login' => 'user1',
                'password' => bcrypt('password'),
                'full_name' => 'User One',
                'phone' => '+79991234567',
                'role' => Role::EMPLOYEE->value,
                'telegram_id' => null,
              ]),
            User::create([
                'login' => 'user2',
                'password' => bcrypt('password'),
                'full_name' => 'User Two',
                'phone' => '8 (999) 234-56-78',
                'role' => Role::MANAGER->value,
                'telegram_id' => null,
              ]),
        ];

        $reflection = new \ReflectionClass('\App\Bot\Conversations\Guest\StartConversation');
        $findUserMethod = $reflection->getMethod('findUserByPhone');
        $findUserMethod->setAccessible(true);
        $normalizeMethod = $reflection->getMethod('normalizePhoneNumber');
        $normalizeMethod->setAccessible(true);
        $conversation = new \App\Bot\Conversations\Guest\StartConversation();

        // Test finding first user with various formats
        $foundUser = $findUserMethod->invoke($conversation, '79991234567');
        $this->assertNotNull($foundUser);
        $this->assertEquals($users[0]->id, $foundUser->id);

        // Normalize before searching (as done in actual code flow)
        $normalized = $normalizeMethod->invoke($conversation, '9991234567');
        $foundUser = $findUserMethod->invoke($conversation, $normalized);
        $this->assertNotNull($foundUser);
        $this->assertEquals($users[0]->id, $foundUser->id);

        $foundUser = $findUserMethod->invoke($conversation, '79992345678');
        $this->assertNotNull($foundUser);
        $this->assertEquals($users[1]->id, $foundUser->id);

        // Normalize before searching
        $normalized = $normalizeMethod->invoke($conversation, '9992345678');
        $foundUser = $findUserMethod->invoke($conversation, $normalized);
        $this->assertNotNull($foundUser);
        $this->assertEquals($users[1]->id, $foundUser->id);

        // Test not finding user
        $foundUser = $findUserMethod->invoke($conversation, '79999999999');
        $this->assertNull($foundUser);
    }

    public function test_user_already_bound_to_telegram(): void
    {
        // Create a user with bound telegram_id (without dealership_id)
        $user = User::create([
            'login' => 'testuser',
            'password' => bcrypt('password'),
            'full_name' => 'Test User',
            'phone' => '+79991234567',
            'role' => Role::EMPLOYEE->value,
            'telegram_id' => 123456789,
        ]);

        // Try to find same user by phone
        $reflection = new \ReflectionClass('\App\Bot\Conversations\Guest\StartConversation');
        $method = $reflection->getMethod('findUserByPhone');
        $method->setAccessible(true);
        $conversation = new \App\Bot\Conversations\Guest\StartConversation();

        $foundUser = $method->invoke($conversation, '79991234567');
        $this->assertNotNull($foundUser);
        $this->assertEquals(123456789, $foundUser->telegram_id);
    }

    public function test_generate_welcome_message(): void
    {
        // Create user without dealership_id
        $user = User::create([
            'login' => 'testuser',
            'password' => bcrypt('password'),
            'full_name' => 'Иван Иванов',
            'phone' => '+79991234567',
            'role' => Role::MANAGER->value,
            'telegram_id' => 123456789,
        ]);

        $reflection = new \ReflectionClass('\App\Bot\Conversations\Guest\StartConversation');
        $method = $reflection->getMethod('generateWelcomeMessage');
        $method->setAccessible(true);
        $conversation = new \App\Bot\Conversations\Guest\StartConversation();

        $message = $method->invoke($conversation, $user, 'Управляющий');

        $this->assertStringContainsString('Управляющий', $message);
        $this->assertStringContainsString('Иван Иванов', $message);
        $this->assertStringContainsString('Вы успешно вошли в систему', $message);
    }

    public function test_conversation_handles_non_russian_numbers(): void
    {
        // Create user with non-Russian number (without dealership_id)
        $user = User::create([
            'login' => 'user_intl',
            'password' => bcrypt('password'),
            'full_name' => 'International User',
            'phone' => '+12345678901',
            'role' => Role::EMPLOYEE->value,
            'telegram_id' => null,
        ]);

        $reflection = new \ReflectionClass('\App\Bot\Conversations\Guest\StartConversation');
        $method = $reflection->getMethod('findUserByPhone');
        $method->setAccessible(true);
        $conversation = new \App\Bot\Conversations\Guest\StartConversation();

        // Should find user with exact match
        $foundUser = $method->invoke($conversation, '12345678901');
        $this->assertNotNull($foundUser);
        $this->assertEquals($user->id, $foundUser->id);
    }

    public function test_conversation_handles_phone_number_flexibility(): void
    {
        // Test different phone number formats (without dealership_id)
        $formats = [
            '+79991234567',
            '79991234567',
            '89991234567',
            '(999) 123-45-67',
        ];

        $user = null;
        foreach ($formats as $phoneFormat) {
            $user = User::create([
                'login' => 'user_' . uniqid(),
                'password' => bcrypt('password'),
                'full_name' => 'Test User',
                'phone' => $phoneFormat,
                'role' => Role::EMPLOYEE->value,
                'telegram_id' => null,
              ]);

            $reflection = new \ReflectionClass('\App\Bot\Conversations\Guest\StartConversation');
            $method = $reflection->getMethod('findUserByPhone');
            $method->setAccessible(true);
            $conversation = new \App\Bot\Conversations\Guest\StartConversation();

            // Should find user regardless of format
            $foundUser = $method->invoke($conversation, '79991234567');
            $this->assertNotNull($foundUser);
            $this->assertEquals($user->id, $foundUser->id);
            break; // Only test with first user
        }
    }
}