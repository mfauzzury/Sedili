<?php

namespace App\Modules\Leave\Reports;

use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveService;
use App\Modules\Report;
use App\Modules\ReportChart;
use App\Modules\ReportColumn;
use App\Modules\ReportFilters;
use App\Modules\ReportResult;
use App\Modules\ReportStat;
use Illuminate\Support\Number;

/**
 * Entitlement, days taken, pending and remaining of a year, by employee and leave type.
 */
class LeaveBalanceReport extends Report
{
    public function __construct(private readonly LeaveService $service) {}

    public function key(): string
    {
        return 'balances';
    }

    public function title(): string
    {
        return 'Leave balances';
    }

    public function description(): string
    {
        return 'Entitlement, days taken, pending and remaining of a year for each employee and leave type.';
    }

    public function icon(): string
    {
        return 'heroicon-o-scale';
    }

    public function period(): string
    {
        return self::YEAR;
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $year = $filters->year();
        $employees = $filters->employees()
            ->where('is_active', true)
            ->with('department:id,name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'employee_code', 'first_name', 'last_name', 'gender', 'hire_date', 'department_id']);
        $types = LeaveType::query()->where('is_active', true)->orderBy('name')->get();

        // Days by employee, leave type and status; a leave belongs to the year of its start date
        $used = [];
        $filters->whereEmployee(Leave::query())
            ->whereIn('status', LeaveService::CHARGED_STATUSES)
            ->tap(fn ($query) => $filters->whereInPeriod($query, 'start_date'))
            ->selectRaw('employee_id, leave_type, status, SUM(days) as total')
            ->groupBy('employee_id', 'leave_type', 'status')
            ->toBase()
            ->get()
            ->each(function ($row) use (&$used) {
                $used[$row->employee_id][$row->leave_type][$row->status] = (float) $row->total;
            });

        $rows = [];
        $byType = [];
        foreach ($employees as $employee) {
            foreach ($types as $type) {
                if ($type->gender_restriction !== null && $employee->gender !== $type->gender_restriction) {
                    continue;
                }
                $entitlement = $this->service->entitlement($employee, $type, $year);
                if ($entitlement === null) {
                    continue;
                }
                $taken = $used[$employee->id][$type->name]['Approved'] ?? 0.0;
                $pending = $used[$employee->id][$type->name]['Pending'] ?? 0.0;

                $rows[] = [
                    'code' => $employee->employee_code,
                    'employee' => $employee->name,
                    'department' => $employee->department?->name,
                    'type' => $type->name,
                    'entitlement' => $entitlement,
                    'taken' => $taken,
                    'pending' => $pending,
                    'remaining' => $entitlement - $taken - $pending,
                ];
                $byType[$type->name]['entitlement'] = ($byType[$type->name]['entitlement'] ?? 0) + $entitlement;
                $byType[$type->name]['used'] = ($byType[$type->name]['used'] ?? 0) + $taken + $pending;
            }
        }

        $rows = collect($rows);
        $usedPercent = array_map(
            fn (array $totals) => $totals['entitlement'] > 0 ? round($totals['used'] / $totals['entitlement'] * 100, 1) : 0,
            $byType,
        );
        $overdrawn = $rows->where('remaining', '<', 0)->count();

        return new ReportResult(
            columns: [
                ReportColumn::text('code', 'Code'),
                ReportColumn::text('employee', 'Employee'),
                ReportColumn::text('department', 'Department'),
                ReportColumn::text('type', 'Leave type'),
                ReportColumn::number('entitlement', 'Entitled', 1),
                ReportColumn::number('taken', 'Taken', 1),
                ReportColumn::number('pending', 'Pending', 1),
                ReportColumn::number('remaining', 'Remaining', 1),
            ],
            rows: $rows->all(),
            stats: [
                new ReportStat('Employees', Number::format($employees->count())),
                new ReportStat('Days entitled', $this->service->format($rows->sum('entitlement'))),
                new ReportStat('Days taken', $this->service->format($rows->sum('taken')), $this->service->format($rows->sum('pending')).' more pending'),
                new ReportStat('Days remaining', $this->service->format($rows->sum('remaining')), $overdrawn > 0 ? "{$overdrawn} over their entitlement" : null),
            ],
            chart: ReportChart::bar("Share of the entitlement used in {$year}", $usedPercent, horizontal: true, unit: '%'),
            note: 'Active employees and leave types with a yearly entitlement. Pending requests are deducted from the remaining days, as in the balance shown to the employees. A leave belongs to the year it starts in.',
        );
    }
}
