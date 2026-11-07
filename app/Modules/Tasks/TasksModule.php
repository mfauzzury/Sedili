<?php

namespace App\Modules\Tasks;

use App\Modules\Module;
use App\Modules\Tasks\Filament\Pages\TaskBoard;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Observers\TaskObserver;

class TasksModule extends Module
{
    public function key(): string
    {
        return 'tasks';
    }

    public function label(): string
    {
        return 'Task Board';
    }

    public function description(): string
    {
        return 'Kanban board to assign and follow tasks.';
    }

    public function filament(string $panelId): array
    {
        return ['pages' => [TaskBoard::class]];
    }

    public function boot(): void
    {
        Task::observe(TaskObserver::class);
    }
}
