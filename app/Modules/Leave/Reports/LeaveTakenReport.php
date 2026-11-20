<?php

namespace App\Modules\Leave\Reports;

use App\Models\Employee;
use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveService;
use App\Modules\Report;
use App\Modules\ReportChart;
use App\Modules\ReportColumn;
use App\Modules\ReportFilters;
use App\Modules\ReportResult;
use App\Modules\ReportStat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Number;

/**
 * Approved leave days of the period, by employee and leave type.
 */
class LeaveTakenReport extends Report
{
    public function __construct(private readonly LeaveService $service) {}

    public function key(): string
    {
        return 'taken';
    }

    public function title(): string
    {
        return 'Leave taken';
    }

    public function description(): string
    {
        return 'Approved leave days of the period by employee and leave type, and the requests still pending.';
    }

    public function icon(): string
    {
        return 'heroicon-o-calendar-days';
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $leaves = $filters->whereEmployee(Leave::query())
            ->whereIn('status', ['Approved', 'Pending'])
            // Overlapping the period (the dates may be stored with a time: compare with the day after)
            ->where('start_date', '<', $filters->to->addDay()->toDateString())
            ->where('end_date', '>=', $filters->from->toDateString())
            ->get(['id', 'employee_id', 'leave_type', 'start_date', 'end_date', 'days', 'status']);

        $types = LeaveType::query()->orderBy('name')->get(['name', 'count_calendar_days'])->keyBy('name');
        // Types of the period that were deleted since keep their column
        $typeNames = $types->keys()->merge($leaves->pluck('leave_type'))->unique()->values();
        $typeKey = fn (string $name) => 'type_'.$typeNames->search($name);

        $employees = Employee::withTrashed()
            ->with('department:id,name')
            ->whereIn('id', $leaves->pluck('employee_id')->unique())
            ->get(['id', 'employee_code', 'first_name', 'last_name', 'department_id'])
            ->keyBy('id');

        /** @var array<int, array<string, float>> $taken employee id => leave type => days */
        $taken = [];
        /** @var array<int, int> $requests employee id => approved requests */
        $requests = [];
        $byType = array_fill_keys($typeNames->all(), 0.0);
        $pendingDays = 0.0;
        $pendingRequests = 0;

        foreach ($leaves as $leave) {
            $days = $this->daysInPeriod($leave, $filters, (bool) $types->get($leave->leave_type)?->count_calendar_days);

            if ($leave->status === 'Pending') {
                $pendingDays += $days;
                $pendingRequests++;

                continue;
            }

            $taken[$leave->employee_id][$leave->leave_type] = ($taken[$leave->employee_id][$leave->leave_type] ?? 0.0) + $days;
            $requests[$leave->employee_id] = ($requests[$leave->employee_id] ?? 0) + 1;
            $byType[$leave->leave_type] += $days;
        }

        $rows = collect($taken)->map(function (array $days, int $employeeId) use ($employees, $typeNames, $typeKey, $requests): array {
            $employee = $employees->get($employeeId);
            $row = [
                'code' => $employee?->employee_code,
                'employee' => $employee->name ?? 'Deleted employee',
                'department' => $employee?->department?->name,
            ];
            foreach ($typeNames as $name) {
                $row[$typeKey($name)] = $days[$name] ?? 0.0;
            }

            return $row + ['total' => array_sum($days), 'requests' => $requests[$employeeId]];
        })->sortBy('employee', SORT_NATURAL | SORT_FLAG_CASE)->values();
        $approvedRequests = array_sum($requests);
        $totalDays = $rows->sum('total');

        // A column per leave type, only for the types taken in the period
        $typeColumns = $typeNames
            ->filter(fn (string $name) => $byType[$name] > 0)
            ->map(fn (string $name) => ReportColumn::number($typeKey($name), $name, 1))
            ->values()
            ->all();

        return new ReportResult(
            columns: [
                ReportColumn::text('code', 'Code'),
                ReportColumn::text('employee', 'Employee'),
                ReportColumn::text('department', 'Department'),
                ...$typeColumns,
                ReportColumn::number('total', 'Total days', 1),
                ReportColumn::number('requests', 'Requests'),
            ],
            rows: $rows->all(),
            stats: [
                new ReportStat('Days taken', $this->service->format($totalDays)),
                new ReportStat('Employees on leave', Number::format($rows->count())),
                new ReportStat('Approved requests', Number::format($approvedRequests)),
                new ReportStat('Pending', $this->service->format($pendingDays).' days', Number::format($pendingRequests).' '.($pendingRequests === 1 ? 'request' : 'requests').' awaiting approval'),
            ],
            chart: ReportChart::bar('Days taken by leave type', array_filter($byType), horizontal: true, unit: 'days'),
            note: 'Approved leave only. A leave that starts before or ends after the period counts only its days inside the period. Weekends and public holidays are not counted, except for the leave types counted in calendar days.',
        );
    }

    /**
     * Leave days of a request that fall inside the period.
     */
    private function daysInPeriod(Leave $leave, ReportFilters $filters, bool $calendarDays): float
    {
        $start = CarbonImmutable::parse($leave->start_date);
        $end = CarbonImmutable::parse($leave->end_date);
        if ($start->gte($filters->from) && $end->lte($filters->to)) {
            return (float) $leave->days;
        }

        return $this->service->countDays($start->max($filters->from), $end->min($filters->to), $calendarDays);
    }
}
