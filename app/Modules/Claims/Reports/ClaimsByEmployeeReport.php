<?php

namespace App\Modules\Claims\Reports;

use App\Models\Employee;
use App\Modules\Claims\Models\Claim;
use App\Modules\Report;
use App\Modules\ReportChart;
use App\Modules\ReportColumn;
use App\Modules\ReportFilters;
use App\Modules\ReportResult;
use App\Modules\ReportStat;
use Illuminate\Support\Number;

/**
 * Amounts claimed by employee, with a chart by department.
 */
class ClaimsByEmployeeReport extends Report
{
    public function key(): string
    {
        return 'by_employee';
    }

    public function title(): string
    {
        return 'Claims by employee';
    }

    public function description(): string
    {
        return 'Who claims how much: claims and amounts per employee, awaiting approval, to pay and paid.';
    }

    public function icon(): string
    {
        return 'heroicon-o-user-group';
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $totals = ClaimTotals::amountsByStatus(ClaimTotals::items($filters))
            ->addSelect('claims.employee_id')
            ->selectRaw('COUNT(DISTINCT claims.id) as claims, COUNT(*) as items')
            ->groupBy('claims.employee_id')
            ->get();
        $employees = Employee::withTrashed()
            ->with('department:id,name')
            ->whereIn('id', $totals->pluck('employee_id'))
            ->get(['id', 'employee_code', 'first_name', 'last_name', 'department_id'])
            ->keyBy('id');

        $rows = $totals->map(function ($row) use ($employees) {
            $employee = $employees->get($row->employee_id);

            return [
                'code' => $employee?->employee_code,
                'employee' => $employee->name ?? 'Deleted employee',
                'department' => $employee?->department?->name,
                'claims' => (int) $row->claims,
                'items' => (int) $row->items,
                'submitted' => round((float) $row->submitted, 2),
                'approved' => round((float) $row->approved, 2),
                'paid' => round((float) $row->paid, 2),
                'total' => round((float) $row->total, 2),
            ];
        })->sortBy('employee', SORT_NATURAL | SORT_FLAG_CASE)->values();

        $byDepartment = $rows
            ->groupBy(fn (array $row) => $row['department'] ?? 'No department')
            ->map(fn ($group) => round($group->sum('total'), 2))
            ->sortDesc()
            ->all();
        $claims = $rows->sum('claims');
        $money = fn (string $key, string $label) => ReportColumn::money($key, $label, Claim::CURRENCY);

        return new ReportResult(
            columns: [
                ReportColumn::text('code', 'Code'),
                ReportColumn::text('employee', 'Employee'),
                ReportColumn::text('department', 'Department'),
                ReportColumn::number('claims', 'Claims'),
                ReportColumn::number('items', 'Items'),
                $money('submitted', 'Awaiting approval'),
                $money('approved', 'To pay'),
                $money('paid', 'Paid'),
                $money('total', 'Total'),
            ],
            rows: $rows->all(),
            stats: [
                new ReportStat('Total claimed', Claim::formatAmount($rows->sum('total'))),
                new ReportStat('Claims', Number::format($claims)),
                new ReportStat('Employees claiming', Number::format($rows->count())),
                new ReportStat('Average per employee', Claim::formatAmount($rows->isEmpty() ? 0 : $rows->sum('total') / $rows->count())),
            ],
            chart: ReportChart::bar('Total claimed by department', $byDepartment, horizontal: true, unit: Claim::CURRENCY),
            note: ClaimTotals::NOTE.' A claim with items in two periods counts in both.',
        );
    }
}
