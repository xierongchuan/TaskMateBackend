<?php

declare(strict_types=1);

namespace App\Bot\Conversations\Guest;

use App\Bot\Abstracts\BaseConversation;
use App\Enums\Role;
use App\Models\User;
use SergiX44\Nutgram\Nutgram;
use Illuminate\Support\Facades\Log;

/**
 * Conversation for user authentication via phone number.
 * Users must be pre-registered through API endpoints.
 */

class StartConversation extends BaseConversation
{
    protected ?string $step = 'askContact';

    /**
     * Ask for user contact for authentication.
     */
    public function askContact(Nutgram $bot)
    {
        $bot->sendMessage(
            text: '🔐 *Вход в систему*\\n\\nДля входа пожалуйста, поделитесь своим номером телефона:\\n\\nℹ️ *Важно:* Ваш аккаунт должен быть предварительно создан администратором.',
            reply_markup: static::contactRequestKeyboard(),
            parse_mode: 'markdown'
        );

        $this->next('getContact');
    }

    /**
     * Process contact and authenticate user.
     */
    public function getContact(Nutgram $bot)
    {
        try {
            $contact = $bot->message()->contact;

            if (!$contact?->phone_number) {
                $bot->sendMessage(
                    '❌ Не удалось получить номер телефона. Пожалуйста, попробуйте ещё раз.',
                    reply_markup: static::contactRequestKeyboard()
                );
                $this->next('getContact');
                return;
            }

            $telegramUserId = $bot->user()?->id;
            if (!$telegramUserId) {
                Log::error('Не удалось получить Telegram ID для пользователя');
                $bot->sendMessage(
                    '❌ Произошла ошибка. Пожалуйста, попробуйте ещё раз.',
                    reply_markup: static::removeKeyboard()
                );
                $this->end();
                return;
            }

            // Normalize and validate phone number
            $phoneNumber = $contact->phone_number;
            $normalizedPhone = $this->normalizePhoneNumber($phoneNumber);

            if (!$this->isValidPhoneNumber($normalizedPhone)) {
                $bot->sendMessage(
                    '❌ Неверный формат номера телефона. Пожалуйста, используйте корректный номер.',
                    reply_markup: static::contactRequestKeyboard()
                );
                $this->next('getContact');
                return;
            }

            Log::info('Попытка входа в систему', [
                'telegram_id' => $telegramUserId,
                'phone' => $phoneNumber,
                'normalized_phone' => $normalizedPhone
            ]);

            // Check for existing Telegram ID binding first
            $existingTelegramUser = User::where('telegram_id', $telegramUserId)->first();
            if ($existingTelegramUser) {
                // User already authenticated with different phone
                if ($this->normalizePhoneNumber($existingTelegramUser->phone) !== $normalizedPhone) {
                    Log::warning('Попытка входа с другого номера', [
                        'telegram_id' => $telegramUserId,
                        'existing_phone' => $existingTelegramUser->phone,
                        'new_phone' => $phoneNumber
                    ]);

                    $bot->sendMessage(
                        '⚠️ Этот Telegram аккаунт уже привязан к другому номеру телефона (' . $existingTelegramUser->phone . ').\\n\\nПожалуйста, свяжитесь с администратором для решения этой проблемы.',
                        reply_markup: static::removeKeyboard(),
                        parse_mode: 'markdown'
                    );
                    $this->end();
                    return;
                }

                // Same user trying to login again
                $this->handleSuccessfulLogin($bot, $existingTelegramUser);
                return;
            }

            // Search user by phone number with multiple matching strategies
            $user = $this->findUserByPhone($normalizedPhone);

            if (!$user) {
                Log::info('Пользователь не найден в системе', [
                    'telegram_id' => $telegramUserId,
                    'phone' => $phoneNumber
                ]);

                $bot->sendMessage(
                    '❌ *Аккаунт не найден*\\n\\nВаш номер телефона не найден в нашей системе.\\n\\n📞 *Свяжитесь с администратором* для создания учетной записи:\\n• Предоставьте свой номер телефона\\n• После создания аккаунта попробуйте войти снова',
                    reply_markup: static::removeKeyboard(),
                    parse_mode: 'markdown'
                );
                $this->end();
                return;
            }

            // Check if phone is already bound to another Telegram account
            if ($user->telegram_id && $user->telegram_id !== $telegramUserId) {
                Log::warning('Номер телефона уже привязан к другому Telegram аккаунту', [
                    'user_id' => $user->id,
                    'phone' => $user->phone,
                    'existing_telegram_id' => $user->telegram_id,
                    'new_telegram_id' => $telegramUserId
                ]);

                $bot->sendMessage(
                    '⚠️ Этот номер телефона уже привязан к другому Telegram аккаунту.\\n\\nПожалуйста, свяжитесь с администратором для решения этой проблемы.',
                    reply_markup: static::removeKeyboard(),
                    parse_mode: 'markdown'
                );
                $this->end();
                return;
            }

            // Update user with Telegram ID
            $user->update(['telegram_id' => $telegramUserId]);

            $this->handleSuccessfulLogin($bot, $user);

        } catch (\Throwable $e) {
            Log::error('Ошибка при обработке входа', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->handleError($bot, $e, 'getContact');
        }
    }

    /**
     * Handle successful user login.
     */
    private function handleSuccessfulLogin(Nutgram $bot, User $user): void
    {
        Log::info('Пользователь успешно вошел в систему', [
            'user_id' => $user->id,
            'full_name' => $user->full_name,
            'role' => $user->role,
            'telegram_id' => $user->telegram_id
        ]);

        // Get appropriate keyboard based on role
        $keyboard = $this->getRoleKeyboard($user->role);
        $roleLabel = Role::tryFromString($user->role)?->label() ?? 'Сотрудник';
        $welcomeMessage = $this->generateWelcomeMessage($user, $roleLabel);

        $bot->sendMessage(
            $welcomeMessage,
            reply_markup: $keyboard,
            parse_mode: 'markdown'
        );

        $this->end();
    }

    /**
     * Generate personalized welcome message.
     */
    private function generateWelcomeMessage(User $user, string $roleLabel): string
    {
        $greeting = match(date('H')) {
            0, 1, 2, 3, 4, 5 => '🌙 Доброй ночи',
            6, 7, 8, 9, 10, 11 => '☀️ Доброе утро',
            12, 13, 14, 15, 16, 17 => '🌤️ Добрый день',
            18, 19, 20, 21 => '🌆 Добрый вечер',
            default => '👋 Добро пожаловать'
        };

        return "{$greeting}, {$roleLabel} *{$user->full_name}*!\\n\\n✅ Вы успешно вошли в систему.\\n\\nВыберите действие в меню ниже:";
    }

    /**
     * Find user by phone number using multiple strategies.
     */
    private function findUserByPhone(string $normalizedPhone): ?User
    {
        // Strategy 1: Direct match with formatted numbers
        $formats = [
            '+' . $normalizedPhone,           // +79991234567
            $normalizedPhone,                 // 79991234567
            '8' . substr($normalizedPhone, 1), // 89991234567 (Russian format)
            substr($normalizedPhone, 1),     // 9991234567 (without country code)
        ];

        foreach ($formats as $format) {
            $user = User::where('phone', $format)->first();
            if ($user) return $user;
        }

        // Strategy 2: LIKE match for flexible matching
        $user = User::where('phone', 'like', '%' . $normalizedPhone . '%')->first();
        if ($user) {
            // Verify it's actually the same number (prevent false positives)
            $userNormalizedPhone = $this->normalizePhoneNumber($user->phone);
            if ($userNormalizedPhone === $normalizedPhone) {
                return $user;
            }
        }

        // Strategy 3: Handle country code variations with LIKE
        if (str_starts_with($normalizedPhone, '7') && strlen($normalizedPhone) === 11) {
            $last10Digits = substr($normalizedPhone, 1);

            // Try with +7 prefix
            $user = User::where('phone', 'like', '%+7' . $last10Digits . '%')->first();
            if ($user) return $user;

            // Try with 8 prefix
            $user = User::where('phone', 'like', '%8' . $last10Digits . '%')->first();
            if ($user) return $user;

            // Try with just 10 digits
            $user = User::where('phone', 'like', '%' . $last10Digits . '%')->first();
            if ($user) {
                $userNormalizedPhone = $this->normalizePhoneNumber($user->phone);
                if ($userNormalizedPhone === $normalizedPhone) {
                    return $user;
                }
            }
        }

        return null;
    }

    /**
     * Get keyboard based on user role.
     */
    private function getRoleKeyboard(string $role)
    {
        return match ($role) {
            Role::EMPLOYEE->value => static::employeeMenu(),
            Role::MANAGER->value => static::managerMenu(),
            Role::OBSERVER->value => static::observerMenu(),
            Role::OWNER->value => static::ownerMenu(),
            default => static::employeeMenu()
        };
    }

    /**
     * Normalize phone number for comparison and validation.
     */
    private function normalizePhoneNumber(string $phone): string
    {
        // Remove all non-digit characters
        $normalized = preg_replace('/\D+/', '', $phone);

        // Handle Russian number format conversions
        if (strlen($normalized) === 11) {
            if (str_starts_with($normalized, '8')) {
                // Convert 8xxx to 7xxx (Russian format)
                $normalized = '7' . substr($normalized, 1);
            }
        } elseif (strlen($normalized) === 10) {
            // Assume Russian number if 10 digits
            $normalized = '7' . $normalized;
        }

        return $normalized;
    }

    /**
     * Validate normalized phone number.
     */
    private function isValidPhoneNumber(string $phone): bool
    {
        // Basic validation: should be 10-15 digits
        $length = strlen($phone);
        return $length >= 10 && $length <= 15;
    }
}
