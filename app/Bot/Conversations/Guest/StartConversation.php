<?php

declare(strict_types=1);

namespace App\Bot\Conversations\Guest;

use App\Bot\Abstracts\BaseConversation;
use App\Enums\Role;
use App\Models\User;
use App\Services\VCRM\UserService as VCRMUserService;
use SergiX44\Nutgram\Nutgram;
use Illuminate\Support\Facades\Log;

/**
 * Conversation for guest registration.
 * Refactored to use base class and follow SOLID principles.
 */

class StartConversation extends BaseConversation
{
    protected ?string $step = 'askContact';

    /**
     * Get VCRM user service from container.
     */
    private function getVcrmUserService(): VCRMUserService
    {
        return app(VCRMUserService::class);
    }

    /**
     * Ask for user contact.
     */
    public function askContact(Nutgram $bot)
    {
        $bot->sendMessage(
            text: 'Привет! Чтобы зарегистрироваться, пожалуйста, поделитесь своим номером телефона:',
            reply_markup: static::contactRequestKeyboard()
        );

        $this->next('getContact');
    }

    /**
     * Process contact and register user.
     */
    public function getContact(Nutgram $bot)
    {
        try {
            $contact = $bot->message()->contact;

            if (!$contact?->phone_number) {
                $bot->sendMessage('Не удалось получить номер телефона. Попробуйте ещё раз.');
                $this->next('getContact');
                return;
            }

            $telegramUserId = $bot->user()?->id;
            if (!$telegramUserId) {
                throw new \RuntimeException('Не удалось получить Telegram ID');
            }

            // Fetch user from VCRM
            $vcrmUser = $this->getVcrmUserService()->fetchByPhone((string) $contact->phone_number);

            if ($vcrmUser === false) {
                $bot->sendMessage(
                    'Ваш аккаунт не зарегистрирован в системе — обратитесь к администратору.',
                    reply_markup: static::removeKeyboard()
                );
                $this->end();
                return;
            }

            // Register or update user
            User::updateOrCreate(
                ['phone' => $vcrmUser->phoneNumber],
                [
                    'login' => $vcrmUser->login,
                    'full_name' => $vcrmUser->fullName,
                    'telegram_id' => $telegramUserId,
                    'role' => $vcrmUser->role,
                    'company_id' => $vcrmUser->company->id,
                ]
            );

            Log::info('Пользователь зарегистрирован: ' . json_encode($vcrmUser));

            // Get appropriate keyboard based on role
            $keyboard = $this->getRoleKeyboard($vcrmUser->role);
            $role = Role::tryFromString($vcrmUser->role)->label();

            $bot->sendMessage(
                'Добро пожаловать ' . $role . ' ' . $vcrmUser->fullName . '!',
                reply_markup: $keyboard
            );

            $this->end();
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'getContact');
        }
    }

    /**
     * Get keyboard based on user role.
     */
    private function getRoleKeyboard(string $role)
    {
        return match ($role) {
            Role::USER->value => static::userMenu(),
            Role::DIRECTOR->value => static::directorMenu(),
            Role::ACCOUNTANT->value => static::accountantMenu(),
            default => static::removeKeyboard()
        };
    }
}
