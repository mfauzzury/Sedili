<?php

namespace App\Modules\Attendance\Reports;

use App\Models\Employee;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Report;
use App\Modules\ReportChart;
use App\Modules\ReportColumn;
use App\Modules\ReportFilters;
use App\Modules\ReportResult;
use App\Modules\ReportStat;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Number;

/**
 * Days worked, hours, late arrivals and missing clock-outs of the period, by employee.
 */
class AttendanceSummaryReport extends Report
{
    /** Up to this number of days the chart has a point per day, a point per month above */
    private const DAILY_CHART_MAX_DAYS = 62;

    public function key(): string
    {
        return 'summary';
    }

    public function title(): string
    {
        return 'Attendance summary';
    }

    public function description(): string
    {
        return 'Days worked, hours, late arrivals and missing clock-outs per employee.';
    }

    public function icon(): string
    {
        return 'heroicon-o-clock';
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $rows = [];
        $presence = [];
        $daily = $filters->from->diffInDays($filters->to) + 1 <= self::DAILY_CHART_MAX_DAYS;

        $records = $filters->whereEmployee(Attendance::query())
            ->without('employee')
            ->with('shift')
            ->tap(fn ($query) => $filters->whereInPeriod($query, 'date'));

        foreach ($records->lazyById(1000) as $record) {
            $row = &$rows[$record->employee_id];
            $row ??= ['dates' => [], 'hours' => 0.0, 'timed_days' => 0, 'late' => 0, 'no_clock_out' => 0];

            $date = CarbonImmutable::parse($record->date)->toDateString();
            $row['dates'][$date] = true;
            $presence[$daily ? $date : substr($date, 0, 7)][$record->employee_id.'|'.$date] = true;

            $hours = $record->hours;
            if ($hours !== null) {
                $row['hours'] += $hours;
                $row['timed_days']++;
            }
            $clockIn = $record->getRawOriginal('clock_in');
            if ($clockIn !== null && $record->getRawOriginal('clock_out') === null) {
                $row['no_clock_out']++;
            }
            // Late: clocked in after the start of the shift (both as H:i:s, so they compare as text;
            // the clock-in may be stored with a date, depending on the database)
            if ($clockIn !== null && $record->shift !== null
                && Carbon::parse($clockIn)->format('H:i:s') > Carbon::parse($record->shift->start_time)->format('H:i:s')) {
                $row['late']++;
            }
            unset($row);
        }

        $employees = Employee::withTrashed()
            ->with('department:id,name')
            ->whereIn('id', array_keys($rows))
            ->get(['id', 'employee_code', 'first_name', 'last_name', 'department_id'])
            ->keyBy('id');

        $rows = collect($rows)->map(function (array $row, int $employeeId) use ($employees) {
            $employee = $employees->get($employeeId);

            return [
                'code' => $employee?->employee_code,
                'employee' => $employee->name ?? 'Deleted employee',
                'department' => $employee?->department?->name,
                'days' => count($row['dates']),
                'hours' => round($row['hours'], 2),
                'average' => $row['timed_days'] > 0 ? round($row['hours'] / $row['timed_days'], 2) : null,
                'late' => $row['late'],
                'no_clock_out' => $row['no_clock_out'],
            ];
        })->sortBy('employee', SORT_NATURAL | SORT_FLAG_CASE)->values();

        $days = $rows->sum('days');
        $late = $rows->sum('late');

        return new ReportResult(
            columns: [
                ReportColumn::text('code', 'Code'),
                ReportColumn::text('employee', 'Employee'),
                ReportColumn::text('department', 'Department'),
                ReportColumn::number('days', 'Days worked'),
                ReportColumn::number('hours', 'Hours', 1),
                ReportColumn::number('average', 'Hours per day', 1),
                ReportColumn::number('late', 'Late arrivals'),
                ReportColumn::number('no_clock_out', 'No clock-out'),
            ],
            rows: $rows->all(),
            stats: [
                new ReportStat('Days worked', Number::format($days), Number::format($rows->count()).' employees'),
                new ReportStat('Hours worked', Number::format($rows->sum('hours'), maxPrecision: 1)),
                new ReportStat('Late arrivals', Number::format($late), $days > 0 ? Number::format($late / $days * 100, maxPrecision: 1).'% of the days' : null),
                new ReportStat('Missing clock-outs', Number::format($rows->sum('no_clock_out'))),
            ],
            chart: $this->chart($presence, $filters, $daily),
            note: 'A late arrival is a clock-in after the start time of the shift (records without a shift are not checked). Hours are counted on the days with both a clock-in and a clock-out.',
        );
    }

    /**
     * Employees present per day, or days worked per month for a long period.
     *
     * @param  array<string, array<string, true>>  $presence  bucket (Y-m-d or Y-m) => employee|date
     */
    private function chart(array $presence, ReportFilters $filters, bool $daily): ReportChart
    {
        $values = [];
        if ($daily) {
            for ($day = $filters->from; $day->lte($filters->to); $day = $day->addDay()) {
                $present = count($presence[$day->toDateString()] ?? []);
                // A weekend nobody worked is not a drop in attendance: leave it out
                if ($present === 0 && $day->isWeekend()) {
                    continue;
                }
                $values[$day->format('D j M')] = $present;
            }

            return ReportChart::line('Employees present per day', $values, 'employees');
        }

        for ($month = $filters->from->startOfMonth(); $month->lte($filters->to); $month = $month->addMonth()) {
            $values[$month->format('M Y')] = count($presence[$month->format('Y-m')] ?? []);
        }

        return ReportChart::bar('Days worked per month', $values, unit: 'days');
    }
}
