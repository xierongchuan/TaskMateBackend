<?php

declare(strict_types=1);

namespace App\Traits;

use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

trait KeyboardTrait
{
    /**
     * Клавиатура пользователя (reply keyboard)
     */
    public static function userMenu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: false)
            ->addRow(
                KeyboardButton::make('📝 Создать заявку'),
                // KeyboardButton::make('📄 Мои заявки')
            );
        // ->addRow(
        //     KeyboardButton::make('📞 Поделиться номером', request_contact: true),
        //     KeyboardButton::make('❓ Помощь')
        // );
    }

    /**
     * Клавиатура директора
     */
    public static function directorMenu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true)
            ->addRow(
                KeyboardButton::make('🔃 Ожидающие заявки')
            );
        // ->addRow(
        //     KeyboardButton::make('🧾 Отчёты'),
        //     KeyboardButton::make('◀️ Назад')
        // );
    }

    /**
     * Кнопка запроса контакта (на одну кнопку) — удобно если нужен только контакт
     */
    public static function contactRequestKeyboard(string $label = 'Отправить номер'): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: true)
            ->addRow(KeyboardButton::make($label, request_contact: true));
    }

    /**
     * Inline клавиатура: Подтвердить / Отменить (callback_data задаются)
     */
    public static function inlineConfirmCancel(
        string $confirmData = 'confirm',
        string $cancelData = 'cancel'
    ): InlineKeyboardMarkup {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(text: '✅ Подтвердить', callback_data: $confirmData),
                InlineKeyboardButton::make(text: '❌ Отменить', callback_data: $cancelData),
            );
    }

    /**
     * ReplyKeyboardRemove — убрать reply keyboard
     */
    public static function removeKeyboard(): ReplyKeyboardRemove
    {
        return ReplyKeyboardRemove::make(true, selective: false);
    }

    /**
     * Сгенерировать inline-клавиатуру из массива:
     * $buttons = [
     *   [ ['text'=>'A','data'=>'a'], ['text'=>'B','data'=>'b'] ],
     *   [ ['text'=>'C','data'=>'c'] ]
     * ];
     */
    public static function inlineFromArray(array $buttons): InlineKeyboardMarkup
    {
        $ik = InlineKeyboardMarkup::make();
        foreach ($buttons as $row) {
            $ikRow = [];
            foreach ($row as $btn) {
                $ikRow[] = InlineKeyboardButton::make(text: $btn['text'], callback_data: $btn['data']);
            }
            $ik->row($ikRow);
        }
        return $ik;
    }

    /**
     * Быстрая reply клавиатура с Yes/No (удобно для простых вопросов)
     */
    public static function yesNoKeyboard(string $yes = 'Да', string $no = 'Нет'): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: true)
            ->addRow(
                KeyboardButton::make($yes),
                KeyboardButton::make($no)
            );
    }
}
