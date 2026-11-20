<?php

namespace App\Modules\Tasks;

use App\Models\Employee;
use App\Modules\Module;
use App\Modules\Todo;
use App\Modules\Tasks\Filament\Pages\TaskBoard;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Observers\TaskObserver;
use App\Modules\Tasks\Reports\TaskWorkloadReport;

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

    /**
     * The open tasks assigned to the employee, the earliest due date first (the ones without date last).
     */
    public function todos(Employee $employee, string $panelId): array
    {
        if ($panelId !== 'portal') {
            return [];
        }

        $limit = 8;
        $tasks = Task::query()
            ->where('assignee_id', $employee->id)
            ->where('status', '!=', 'completed')
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $url = TaskBoard::getUrl();
        $todos = [];
        foreach ($tasks->take($limit) as $task) {
            [$badge, $color] = $this->dueLabel($task->due_date);
            $todos[] = new Todo(
                title: $task->title,
                url: $url,
                detail: $task->status === 'in_progress' ? 'In progress' : 'To do',
                badge: $badge,
                color: $color,
                icon: 'heroicon-o-clipboard-document-check',
            );
        }
        if ($tasks->count() > $limit) {
            $more = Task::query()->where('assignee_id', $employee->id)->where('status', '!=', 'completed')->count() - $limit;
            $todos[] = new Todo(title: "$more more open tasks", url: $url, icon: 'heroicon-o-ellipsis-horizontal-circle');
        }

        return $todos;
    }

    /**
     * @return array{0: string|null, 1: 'danger'|'warning'|'gray'}
     */
    private function dueLabel(?\Carbon\CarbonInterface $due): array
    {
        if ($due === null) {
            return [null, 'gray'];
        }
        $today = now()->startOfDay();
        $days = (int) $today->diffInDays($due->copy()->startOfDay(), false);

        return match (true) {
            $days < 0 => ['Overdue by ' . abs($days) . ($days === -1 ? ' day' : ' days'), 'danger'],
            $days === 0 => ['Due today', 'warning'],
            $days === 1 => ['Due tomorrow', 'warning'],
            default => ['Due ' . $due->format('j M'), 'gray'],
        };
    }

    public function boot(): void
    {
        Task::observe(TaskObserver::class);
    }

    public function reports(): array
    {
        return [new TaskWorkloadReport];
    }
}
