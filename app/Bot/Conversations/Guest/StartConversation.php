<?php

declare(strict_types=1);

namespace App\Bot\Conversations\Guest;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use Illuminate\Support\Facades\DB;
use App\Models\ExpenseRequest;
use App\Models\ExpenseApproval;
use App\Models\User;
use App\Services\VCRM\UserService as VCRMUserService;
use Illuminate\Support\Facades\Log;

class StartConversation extends Conversation
{
    // Стартовый шаг
    protected ?string $step = 'askContact';

    public function askContact(Nutgram $bot)
    {
        $keyboard = ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: true)
        ->addRow(
            KeyboardButton::make('Отправить номер телефона', request_contact: true)
        );

        $bot->sendMessage(
            text: 'Привет! Чтобы зарегистрироваться, пожалуйста, поделитесь своим номером телефона:',
            reply_markup: $keyboard
        );

        $this->next('getContact');
    }

    public function getContact(Nutgram $bot)
    {
        $contact = $bot->message()->contact;

        if (!$contact?->phone_number) {
            $bot->sendMessage('Не удалось получить номер телефона. Попробуйте ещё раз.');
            return;
        }

        $telegramUserId = $bot->user()?->id;

        $VCRMUserClient = new VCRMUserService();

        $VCRMUser = $VCRMUserClient->fetchByPhone((string) $contact->phone_number);

        if ($VCRMUser === false) {
            $bot->sendMessage('Ваш аккаунт не зарегистрирован в системе — обратитесь к администратору.');
            return;
        }

        User::updateOrCreate(
            // Условие поиска
            ['phone' => $VCRMUser->phoneNumber],
            // Данные для обновления/создания
            [
                'login'       => $VCRMUser->login,
                'full_name'   => $VCRMUser->fullName,
                'telegram_id' => $telegramUserId,
                'role'        => $VCRMUser->role,
                'company_id'  => $VCRMUser->company->id,
            ]
        );

        Log::info('Пользователь зарегистрирован: ' . json_encode($VCRMUser));

        $bot->sendMessage("Здравствуйте $VCRMUser->fullName \nВы успешно зарегистрированы!");
        $this->end();
    }

    public function closing(Nutgram $bot)
    {
        //
    }
}
