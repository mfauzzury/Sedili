<?php

namespace App\Modules\Reports\Reports;

use App\Models\Department;
use App\Models\Employee;
use App\Modules\Report;
use App\Modules\ReportChart;
use App\Modules\ReportColumn;
use App\Modules\ReportFilters;
use App\Modules\ReportResult;
use App\Modules\ReportStat;
use Carbon\CarbonInterface;
use Illuminate\Support\Number;

/**
 * Headcount by department at the end of the period, with the joiners, the leavers and the turnover.
 */
class HeadcountReport extends Report
{
    public const EMPLOYMENT_TYPES = ['Permanent', 'Contract', 'Casual'];

    public function key(): string
    {
        return 'headcount';
    }

    public function title(): string
    {
        return 'Headcount and turnover';
    }

    public function description(): string
    {
        return 'Employees by department and employment type, with the joiners, leavers and turnover of the period.';
    }

    public function icon(): string
    {
        return 'heroicon-o-users';
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $employees = $filters->employees()
            ->get(['id', 'department_id', 'employment_type', 'hire_date', 'termination_date', 'is_active']);
        $departments = Department::query()->pluck('name', 'id');

        $rows = [];
        $atStart = 0;
        foreach ($employees as $employee) {
            $departmentId = $employee->department_id ?? 0;
            $rows[$departmentId] ??= [
                'department' => $departments[$departmentId] ?? 'No department',
                'headcount' => 0,
                ...array_fill_keys(array_map('strtolower', self::EMPLOYMENT_TYPES), 0),
                'joiners' => 0,
                'leavers' => 0,
            ];
            $row = &$rows[$departmentId];

            if ($this->employedOn($employee, $filters->to)) {
                $row['headcount']++;
                $type = strtolower((string) $employee->employment_type);
                if (array_key_exists($type, $row)) {
                    $row[$type]++;
                }
            }
            if ($this->employedOn($employee, $filters->from->subDay())) {
                $atStart++;
            }
            if ($employee->hire_date?->betweenIncluded($filters->from, $filters->to)) {
                $row['joiners']++;
            }
            if ($employee->termination_date?->betweenIncluded($filters->from, $filters->to)) {
                $row['leavers']++;
            }
            unset($row);
        }

        $rows = collect($rows)
            ->filter(fn (array $row) => $row['headcount'] + $row['joiners'] + $row['leavers'] > 0)
            ->sortBy('department', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $headcount = $rows->sum('headcount');
        $leavers = $rows->sum('leavers');
        $average = ($atStart + $headcount) / 2;
        $turnover = $average > 0 ? $leavers / $average * 100 : 0;

        return new ReportResult(
            columns: [
                ReportColumn::text('department', 'Department'),
                ReportColumn::number('headcount', 'Headcount'),
                ReportColumn::number('permanent', 'Permanent'),
                ReportColumn::number('contract', 'Contract'),
                ReportColumn::number('casual', 'Casual'),
                ReportColumn::number('joiners', 'Joiners'),
                ReportColumn::number('leavers', 'Leavers'),
            ],
            rows: $rows->all(),
            stats: [
                new ReportStat('Headcount', Number::format($headcount), 'On '.$filters->to->format('j M Y')),
                new ReportStat('Joiners', Number::format($rows->sum('joiners')), 'Hired in the period'),
                new ReportStat('Leavers', Number::format($leavers), 'Terminated in the period'),
                new ReportStat('Turnover', Number::format($turnover, maxPrecision: 1).'%', 'Leavers / average headcount'),
            ],
            chart: ReportChart::bar('Headcount by department', $rows->pluck('headcount', 'department')->all(), horizontal: true, unit: 'employees'),
            note: 'An employee counts from their hire date until their termination date. An inactive employee without a termination date is not counted. Each employee counts in their current department.',
        );
    }

    /**
     * Was the employee employed on that day?
     */
    private function employedOn(Employee $employee, CarbonInterface $day): bool
    {
        if ($employee->hire_date !== null && $employee->hire_date->gt($day)) {
            return false;
        }
        if ($employee->termination_date !== null) {
            return $employee->termination_date->gt($day);
        }

        return (bool) ($employee->is_active ?? true);
    }
}
