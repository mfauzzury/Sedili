<?php

namespace App\Modules\Tasks\ChatTools;

use App\Models\Employee;
use App\Modules\ChatTool;
use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * "What do I have to do?": the tasks assigned to the employee who is chatting.
 */
class MyTasksTool extends ChatTool
{
    private const LIMIT = 20;

    private const STATUS_LABELS = ['todo' => 'To do', 'in_progress' => 'In progress', 'completed' => 'Completed'];

    public function key(): string
    {
        return 'open';
    }

    public function label(): string
    {
        return 'My tasks';
    }

    public function description(): string
    {
        return 'Returns the tasks assigned to the employee who is asking (up to '.self::LIMIT.'): title, status (To do, In progress or Completed), due date, the days until it is due (negative when it is overdue) and a short description. Only the tasks still to do unless completed ones are asked for, soonest due first. Use it for questions such as "what is due this week?" or "do I have overdue tasks?". It cannot look up anyone else.';
    }

    public function dataSent(): string
    {
        return "The employee's assigned tasks: title, status, due date and the start of the description.";
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_completed' => ['type' => 'boolean', 'description' => 'Also list the completed tasks. Leave out or false for the open ones only.'],
            ],
        ];
    }

    public function run(Employee $employee, array $input): array
    {
        $includeCompleted = ($input['include_completed'] ?? false) === true;
        $today = now()->startOfDay();

        $tasks = Task::query()
            ->where('assignee_id', $employee->id)
            ->when(! $includeCompleted, fn ($query) => $query->where('status', '!=', 'completed'))
            ->orderByRaw("CASE WHEN status = 'completed' THEN 1 ELSE 0 END")
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        return [
            'open_tasks' => Task::query()->where('assignee_id', $employee->id)->where('status', '!=', 'completed')->count(),
            'tasks' => $tasks->map(fn (Task $task): array => Arr::whereNotNull([
                'title' => $task->title,
                'status' => self::STATUS_LABELS[$task->status] ?? $task->status,
                'due_date' => $task->due_date?->toDateString(),
                'days_until_due' => $task->due_date !== null && $task->status !== 'completed'
                    ? (int) $today->diffInDays($task->due_date->copy()->startOfDay(), false)
                    : null,
                'description' => $task->description !== null && $task->description !== '' ? Str::limit(strip_tags($task->description), 200) : null,
            ]))->all(),
        ];
    }
}
