<?php

declare(strict_types=1);

namespace App\Bot\Commands\Owner;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Models\User;
use App\Models\AutoDealership;
use SergiX44\Nutgram\Nutgram;

/**
 * Command for owners to view dealerships
 */
class ViewDealershipsCommand extends BaseCommandHandler
{
    protected string $command = 'viewdealerships';
    protected ?string $description = 'Просмотр салонов';

    protected function execute(Nutgram $bot, User $user): void
    {
        // Get all dealerships
        $dealerships = AutoDealership::withCount('users')->get();

        $message = "🏢 *Автосалоны*\n\n";

        if ($dealerships->isEmpty()) {
            $message .= "Нет автосалонов в системе.\n";
        } else {
            foreach ($dealerships as $dealership) {
                $message .= "*{$dealership->name}*\n";
                $message .= "📍 {$dealership->address}\n";
                $message .= "👥 Сотрудников: {$dealership->users_count}\n\n";
            }
        }

        $message .= "💡 Для управления салонами используйте веб-админку.";

        $bot->sendMessage($message, parse_mode: 'Markdown');
    }
}
