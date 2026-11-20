<?php

namespace App\Modules\Payroll\Reports;

use App\Modules\Payroll\Models\Payroll;
use App\Modules\Report;
use App\Modules\ReportChart;
use App\Modules\ReportColumn;
use App\Modules\ReportFilters;
use App\Modules\ReportResult;
use App\Modules\ReportStat;
use Illuminate\Support\Number;

/**
 * Gross pay, deductions and net pay by pay period.
 */
class PayrollSummaryReport extends Report
{
    public function key(): string
    {
        return 'summary';
    }

    public function title(): string
    {
        return 'Payroll summary';
    }

    public function description(): string
    {
        return 'Payslips, gross pay, deductions and net pay for each pay period.';
    }

    public function icon(): string
    {
        return 'heroicon-o-banknotes';
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $rows = $filters->whereEmployee(Payroll::query()->toBase())
            ->where('status', '!=', 'cancelled')
            ->tap(fn ($query) => $filters->whereInPeriod($query, 'pay_date'))
            ->selectRaw(
                'period, MIN(pay_date) as first_pay_date, MAX(pay_date) as pay_date, COUNT(*) as payslips, '
                .'SUM(gross_pay) as gross, SUM(net_pay) as net, '
                ."SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending"
            )
            ->groupBy('period')
            ->orderBy('first_pay_date')
            ->get()
            ->map(fn ($row) => [
                'period' => $row->period,
                'pay_date' => substr((string) $row->pay_date, 0, 10),
                'payslips' => (int) $row->payslips,
                'gross' => round((float) $row->gross, 2),
                'deductions' => round((float) $row->gross - (float) $row->net, 2),
                'net' => round((float) $row->net, 2),
                'pending' => (int) $row->pending,
            ]);

        $amount = fn (float $value) => Number::format($value, 2);

        return new ReportResult(
            columns: [
                ReportColumn::text('period', 'Period'),
                ReportColumn::date('pay_date', 'Pay date'),
                ReportColumn::number('payslips', 'Payslips'),
                ReportColumn::number('gross', 'Gross pay', 2),
                ReportColumn::number('deductions', 'Deductions', 2),
                ReportColumn::number('net', 'Net pay', 2),
                ReportColumn::number('pending', 'Pending'),
            ],
            rows: $rows->all(),
            stats: [
                new ReportStat('Payslips', Number::format($rows->sum('payslips')), Number::format($rows->count()).' '.($rows->count() === 1 ? 'period' : 'periods')),
                new ReportStat('Gross pay', $amount($rows->sum('gross'))),
                new ReportStat('Deductions', $amount($rows->sum('deductions')), 'Gross pay minus net pay'),
                new ReportStat('Net pay', $amount($rows->sum('net')), $rows->sum('pending') > 0 ? $rows->sum('pending').' payslips still pending' : null),
            ],
            chart: ReportChart::bar('Net pay by period', $rows->pluck('net', 'period')->all()),
            note: 'Payslips paid in the period, cancelled ones excluded. Pending payslips are included in the amounts.',
        );
    }
}
