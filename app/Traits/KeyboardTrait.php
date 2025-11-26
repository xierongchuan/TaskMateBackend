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
     * Клавиатура сотрудника (Employee)
     */
    public static function employeeMenu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: false)
            ->addRow(
                KeyboardButton::make('🔓 Открыть смену'),
                KeyboardButton::make('🔒 Закрыть смену')
            );
        // TODO: Add task response buttons when implemented
    }

    /**
     * Клавиатура управляющего (Manager)
     */
    public static function managerMenu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true)
            ->addRow(
                KeyboardButton::make('📊 Смены'),
                KeyboardButton::make('📋 Задачи')
            );
        // TODO: Add more manager functions via web admin panel
    }

    /**
     * Клавиатура наблюдателя (Observer)
     */
    public static function observerMenu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true)
            ->addRow(
                KeyboardButton::make('👀 Просмотр смен'),
                KeyboardButton::make('📋 Просмотр задач')
            );
    }

    /**
     * Клавиатура владельца (Owner)
     */
    public static function ownerMenu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true)
            ->addRow(
                KeyboardButton::make('🏢 Салоны'),
                KeyboardButton::make('👥 Сотрудники')
            )
            ->addRow(
                KeyboardButton::make('📊 Смены'),
                KeyboardButton::make('📋 Задачи')
            );
        // TODO: Full access to all features
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
     * Inline клавиатура: Подтвердить выдачу (callback_data задаются)
     */
    public static function inlineConfirmIssued(
        string $confirmData = 'confirm',
    ): InlineKeyboardMarkup {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(text: '✅ Выдано', callback_data: $confirmData),
            );
    }

    /**
     * Inline клавиатура: Подтвердить выдачу полной суммы / Выдать иную сумму
     */
    public static function inlineConfirmIssuedWithAmount(
        string $confirmFullData = 'confirm_full',
        string $confirmDifferentData = 'confirm_different_amount'
    ): InlineKeyboardMarkup {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(text: '✅ Выдать полную сумму', callback_data: $confirmFullData),
            )
            ->addRow(
                InlineKeyboardButton::make(text: '💰 Выдать иную сумму', callback_data: $confirmDifferentData),
            );
    }

    /**
     * Inline клавиатура: Подтвердить / Отменить (callback_data задаются)
     */
    public static function inlineConfirmDecline(
        string $confirmData = 'confirm',
        string $declineData = 'decline'
    ): InlineKeyboardMarkup {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(text: '✅ Подтвердить', callback_data: $confirmData),
                InlineKeyboardButton::make(text: '❌ Отменить', callback_data: $declineData),
            );
    }

    /**
     * Inline клавиатура: Подтвердить / Подтвердить с комментом / Отменить (callback_data задаются)
     */
    public static function inlineConfirmCommentDecline(
        string $confirmData = 'confirm',
        string $confirmWithCommentData = 'confirm_with_comment',
        string $declineData = 'decline'
    ): InlineKeyboardMarkup {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(text: '✅ Подтвердить', callback_data: $confirmData),
                InlineKeyboardButton::make(text: '❌ Отменить', callback_data: $declineData),
            )
            ->addRow(
                InlineKeyboardButton::make(
                    text: '💬 Подтвердить с комментарием',
                    callback_data: $confirmWithCommentData
                ),
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

    /**
     * Inline keyboard with cancel button
     */
    public static function cancelKeyboard(string $text = '❌ Отменить', string $data = 'cancel'): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(text: $text, callback_data: $data));
    }
}
