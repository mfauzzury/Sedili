<?php

namespace App\Modules\Inbox;

use App\Modules\Inbox\Filament\Resources\Messages\MessageResource;
use App\Modules\Inbox\Models\Message;
use App\Modules\Inbox\Observers\MessageObserver;
use App\Modules\Module;

class InboxModule extends Module
{
    public function key(): string
    {
        return 'inbox';
    }

    public function label(): string
    {
        return 'Inbox';
    }

    public function description(): string
    {
        return 'Internal messaging between users.';
    }

    public function filament(string $panelId): array
    {
        return ['resources' => [MessageResource::class]];
    }

    public function boot(): void
    {
        Message::observe(MessageObserver::class);
    }
}
