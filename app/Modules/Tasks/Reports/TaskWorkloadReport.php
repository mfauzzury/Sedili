<?php

namespace App\Modules\Tasks\Reports;

use App\Models\Employee;
use App\Modules\Report;
use App\Modules\ReportChart;
use App\Modules\ReportColumn;
use App\Modules\ReportFilters;
use App\Modules\ReportResult;
use App\Modules\ReportStat;
use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Number;

/**
 * Tasks of the board by assignee and status, today.
 */
class TaskWorkloadReport extends Report
{
    private const CHART_MAX = 10;

    public function key(): string
    {
        return 'workload';
    }

    public function title(): string
    {
        return 'Task workload';
    }

    public function description(): string
    {
        return 'Open, in progress, overdue and completed tasks of each person on the task board.';
    }

    public function icon(): string
    {
        return 'heroicon-o-clipboard-document-check';
    }

    public function period(): string
    {
        return self::NONE;
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $query = Task::query()->toBase();
        // A department: the tasks of its people (unassigned tasks belong to no department)
        if ($filters->departmentId !== null) {
            $filters->whereEmployee($query, 'assignee_id');
        }

        $totals = $query
            ->selectRaw(
                'assignee_id, '
                ."SUM(CASE WHEN status = 'todo' THEN 1 ELSE 0 END) as todo, "
                ."SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress, "
                ."SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed, "
                ."SUM(CASE WHEN status != 'completed' AND due_date < ? THEN 1 ELSE 0 END) as overdue",
                [$filters->to->toDateString()],
            )
            ->groupBy('assignee_id')
            ->get();
        $employees = Employee::withTrashed()
            ->with('department:id,name')
            ->whereIn('id', $totals->pluck('assignee_id')->filter())
            ->get(['id', 'first_name', 'last_name', 'department_id'])
            ->keyBy('id');

        $rows = $totals->map(function ($row) use ($employees) {
            $employee = $row->assignee_id ? $employees->get($row->assignee_id) : null;

            return [
                'employee' => $row->assignee_id === null ? 'Unassigned' : ($employee->name ?? 'Deleted employee'),
                'department' => $employee?->department?->name,
                'todo' => (int) $row->todo,
                'in_progress' => (int) $row->in_progress,
                'open' => (int) $row->todo + (int) $row->in_progress,
                'overdue' => (int) $row->overdue,
                'completed' => (int) $row->completed,
            ];
        })->sortBy('employee', SORT_NATURAL | SORT_FLAG_CASE)->values();

        $busiest = $rows->where('open', '>', 0)->sortByDesc('open')->take(self::CHART_MAX);
        $chartTitle = $rows->where('open', '>', 0)->count() > self::CHART_MAX
            ? 'Open tasks: the '.self::CHART_MAX.' busiest people'
            : 'Open tasks by person';

        return new ReportResult(
            columns: [
                ReportColumn::text('employee', 'Assignee'),
                ReportColumn::text('department', 'Department'),
                ReportColumn::number('todo', 'To do'),
                ReportColumn::number('in_progress', 'In progress'),
                ReportColumn::number('open', 'Open'),
                ReportColumn::number('overdue', 'Overdue'),
                ReportColumn::number('completed', 'Completed'),
            ],
            rows: $rows->all(),
            stats: [
                new ReportStat('Open tasks', Number::format($rows->sum('open')), Number::format($rows->sum('todo')).' to do, '.Number::format($rows->sum('in_progress')).' in progress'),
                new ReportStat('Overdue', Number::format($rows->sum('overdue')), 'Open and past their due date'),
                new ReportStat('Completed', Number::format($rows->sum('completed'))),
            ],
            chart: ReportChart::bar($chartTitle, $busiest->pluck('open', 'employee')->all(), horizontal: true, unit: 'tasks'),
            note: 'Open = to do + in progress.',
        );
    }
}
