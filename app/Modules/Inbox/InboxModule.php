<?php

namespace App\Modules\Inbox;

use App\Modules\Inbox\Filament\Resources\Messages\MessageResource;
use App\Modules\Inbox\Models\Message;
use App\Modules\Inbox\Observers\MessageObserver;
use App\Models\Employee;
use App\Modules\Module;
use App\Modules\Todo;

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

    /**
     * One line with the unread messages of the employee.
     */
    public function todos(Employee $employee, string $panelId): array
    {
        if ($panelId !== 'portal') {
            return [];
        }

        $unread = Message::query()->where('receiver_id', $employee->id)->whereNull('read_at');
        $count = (clone $unread)->count();
        if ($count === 0) {
            return [];
        }

        $latest = (clone $unread)->latest('id')->first();

        return [new Todo(
            title: $count === 1 ? '1 unread message' : "$count unread messages",
            url: MessageResource::getUrl('index'),
            detail: $latest?->sender ? 'Latest from ' . $latest->sender->name : null,
            color: 'warning',
            icon: 'heroicon-o-envelope',
        )];
    }

    public function boot(): void
    {
        Message::observe(MessageObserver::class);
    }
}
