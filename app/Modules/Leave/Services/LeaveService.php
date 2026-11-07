<?php

namespace App\Modules\Leave\Services;

use App\Models\Employee;
use App\Modules\Leave\Models\Holiday;
use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Models\LeaveType;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Leave rules: working days, yearly entitlements, balances and request validation.
 *
 * Rules and limits:
 * - Saturdays, Sundays and the dates of the holidays table are not working days.
 * - A leave is attributed to the year of its start date.
 * - Pending and approved leaves are charged to the balance.
 * - The entitlement is not pro-rated for employees hired during the year.
 */
class LeaveService
{
    public const CHARGED_STATUSES = ['Pending', 'Approved'];

    /**
     * Number of leave days between two dates (both included).
     *
     * @param bool $calendarDays count every day, weekends and holidays included
     */
    public function countDays(CarbonInterface $start, CarbonInterface $end, bool $calendarDays = false): float
    {
        $start = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse($end)->startOfDay();
        if ($end->lt($start)) {
            return 0.0;
        }
        if ($calendarDays) {
            return (float) ($start->diffInDays($end) + 1);
        }

        $holidays = Holiday::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('date')
            ->map(fn($date) => Carbon::parse($date)->toDateString())
            ->flip();

        $days = 0;
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            if ($day->isWeekend() || $holidays->has($day->toDateString())) {
                continue;
            }
            $days++;
        }
        return (float) $days;
    }

    /**
     * Yearly entitlement of an employee for a leave type, NULL if the type has no limit.
     */
    public function entitlement(Employee $employee, LeaveType $type, int $year): ?float
    {
        $tiers = $type->tiers;
        if (empty($tiers)) {
            return $type->days_per_year;
        }

        $years = 0;
        if ($employee->hire_date !== null) {
            $reference = Carbon::create($year, 1, 1);
            $years = (int) floor(max(0, Carbon::parse($employee->hire_date)->diffInYears($reference)));
        }

        usort($tiers, fn($a, $b) => $a['min_years'] <=> $b['min_years']);
        $days = (float) $tiers[0]['days'];
        foreach ($tiers as $tier) {
            if ($years >= $tier['min_years']) {
                $days = (float) $tier['days'];
            }
        }
        return $days;
    }

    /**
     * Days already charged (pending and approved) for an employee, a leave type and a year.
     */
    public function chargedDays(int $employeeId, string $leaveType, int $year, ?int $excludeLeaveId = null): float
    {
        return (float) Leave::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type', $leaveType)
            ->whereIn('status', self::CHARGED_STATUSES)
            ->whereYear('start_date', $year)
            ->when($excludeLeaveId, fn($query) => $query->where('id', '!=', $excludeLeaveId))
            ->sum('days');
    }

    /**
     * Days charged by employee and leave type for a year: [employee_id][leave_type] => days.
     *
     * @return array<int, array<string, float>>
     */
    public function chargedDaysByEmployee(int $year): array
    {
        $map = [];
        $rows = Leave::query()
            ->selectRaw('employee_id, leave_type, SUM(days) as total')
            ->whereIn('status', self::CHARGED_STATUSES)
            ->whereYear('start_date', $year)
            ->groupBy('employee_id', 'leave_type')
            ->get();
        foreach ($rows as $row) {
            $map[$row->employee_id][$row->leave_type] = (float) $row->total;
        }
        return $map;
    }

    /**
     * Remaining days, NULL if the leave type has no limit.
     */
    public function balance(Employee $employee, LeaveType $type, int $year, ?int $excludeLeaveId = null): ?float
    {
        $entitlement = $this->entitlement($employee, $type, $year);
        if ($entitlement === null) {
            return null;
        }
        return $entitlement - $this->chargedDays($employee->id, $type->name, $year, $excludeLeaveId);
    }

    /**
     * Validate a leave request, returns the list of error messages (empty if valid).
     *
     * @return list<string>
     */
    public function validate(
        int $employeeId,
        string $leaveType,
        mixed $startDate,
        mixed $endDate,
        ?string $status = 'Pending',
        ?int $excludeLeaveId = null
    ): array {
        if ($status === 'Rejected' || empty($startDate) || empty($endDate)) {
            return [];
        }

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();
        if ($end->lt($start)) {
            return ['The end date must be on or after the start date.'];
        }

        $employee = Employee::find($employeeId);
        $type = LeaveType::where('name', $leaveType)->first();
        if ($employee === null || $type === null) {
            return ['Unknown employee or leave type.'];
        }

        $errors = [];

        if ($type->gender_restriction !== null && $employee->gender !== $type->gender_restriction) {
            $errors[] = "{$type->name} is only available to {$type->gender_restriction} employees.";
        }

        $days = $this->countDays($start, $end, $type->count_calendar_days);
        if ($days <= 0) {
            $errors[] = 'The selected dates contain no working day (weekends and public holidays are excluded).';
        }

        $overlap = Leave::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', self::CHARGED_STATUSES)
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->when($excludeLeaveId, fn($query) => $query->where('id', '!=', $excludeLeaveId))
            ->first();
        if ($overlap !== null) {
            $errors[] = sprintf(
                'This employee already has a %s leave request from %s to %s.',
                $overlap->leave_type,
                $overlap->start_date->format('d M Y'),
                $overlap->end_date->format('d M Y')
            );
        }

        $balance = $this->balance($employee, $type, $start->year, $excludeLeaveId);
        if ($balance !== null && $days > $balance) {
            $errors[] = sprintf(
                'Not enough %s balance: %s day(s) requested, %s day(s) left in %d.',
                $type->name,
                $this->format($days),
                $this->format(max(0, $balance)),
                $start->year
            );
        }

        return $errors;
    }

    /**
     * Format a number of days without useless decimals (8.0 => "8", 2.5 => "2.5").
     */
    public function format(float $days): string
    {
        return rtrim(rtrim(number_format($days, 1, '.', ''), '0'), '.');
    }
}
