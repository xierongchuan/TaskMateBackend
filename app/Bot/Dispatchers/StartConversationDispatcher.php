<?php

declare(strict_types=1);

namespace App\Bot\Dispatchers;

use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use App\Models\User;
use Throwable;

class StartConversationDispatcher
{
    public function __invoke(Nutgram $bot): void
    {
        $telegramUserId = $bot->user()->id ?? null;
        $user = null;

        if ($telegramUserId) {
            $user = User::where('telegram_id', (string)$telegramUserId)->first();

            if (!empty($user)) {
                app()->instance('telegram_user', $user);
                auth()->setUser($user);
            }
        }

        $role = $user->role ?? 'guest';

        $map = [
            'guest'      => \App\Bot\Conversations\Guest\StartConversation::class,
            'user'      => \App\Bot\Commands\User\StartCommand::class,
            'director'   => \App\Bot\Commands\Director\StartCommand::class,
            'accountant' => \App\Bot\Commands\Accountant\StartCommand::class,
        ];

        $target = $map[$role] ?? null;

        if (! $target) {
            $bot->sendMessage('Ваша роль не поддерживает эту команду.');
            Log::warning('expense.command.no_handler', ['tg_id' => $telegramUserId, 'role' => $role]);
            return;
        }

        try {
            // Попытка надёжного резолва: make вместо простого app($target)
            // (app()->make === resolve и бросит исключение при проблеме)
            $handler = app()->make($target);

            if ($handler === null) {
                throw new \RuntimeException('Handler resolved to null');
            }

            // Диагностический лог (полезно в проде для отладки)
            Log::debug('expense.command.handler_resolved', [
                'target' => $target,
                'handler_class' => is_object($handler) ? get_class($handler) : gettype($handler),
            ]);

            // 1) Conversation — стартуем
            if ($handler instanceof \SergiX44\Nutgram\Conversations\Conversation) {
                // $bot->startConversation($handler);
                $target::begin($bot);
                return;
            }

            // 2) Invokable объект (есть __invoke)
            if (is_callable($handler)) {
                $handler($bot);
                return;
            }

            // 3) метод handle(Nutgram $bot)
            if (method_exists($handler, 'handle')) {
                $handler->handle($bot);
                return;
            }

            // 4) instance begin()
            if (method_exists($handler, 'begin')) {
                $handler->begin($bot);
                return;
            }

            // 5) статический begin() на классе
            if (method_exists($target, 'begin')) {
                $target::begin($bot);
                return;
            }

            // 6) если объект — объект Nutgram Command из пакета (например, наследник Command),
            // попробуем вызвать handle через container invocation (предположим handle публичен)
            if (is_object($handler) && method_exists($handler, '__invoke')) {
                $handler($bot);
                return;
            }

            // Ни один вариант не подошёл — логируем подробности
            Log::error('expense.command.invalid_handler', [
                'target' => $target,
                'handler' => is_object($handler) ? get_class($handler) : gettype($handler),
                'tg_id' => $telegramUserId,
            ]);

            $bot->sendMessage('Неверная конфигурация обработчика команды. Свяжитесь с админом.');
        } catch (Throwable $e) {
            // Больше деталей в логе — чтобы понять, где резолв/вызов падает
            Log::error('expense.command.exception', [
                'message' => $e->getMessage(),
                'target'  => $target,
                'tg_id'   => $telegramUserId,
                'trace'   => $e->getTraceAsString(),
            ]);

            // Если причина — что-то связанное с резолвом, попробуем защитно вернуть понятное сообщение пользователю
            $bot->sendMessage('Произошла ошибка при обработке команды.');
        }
    }
}
