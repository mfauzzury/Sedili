<?php

namespace App\Modules\Claims\Reports;

use App\Modules\Claims\Models\Claim;
use App\Modules\Claims\Models\ClaimType;
use App\Modules\Report;
use App\Modules\ReportChart;
use App\Modules\ReportColumn;
use App\Modules\ReportFilters;
use App\Modules\ReportResult;
use App\Modules\ReportStat;

/**
 * Amounts claimed by claim type, split by where they stand (awaiting approval, to pay, paid).
 */
class ClaimsByTypeReport extends Report
{
    public function key(): string
    {
        return 'by_type';
    }

    public function title(): string
    {
        return 'Spending by claim type';
    }

    public function description(): string
    {
        return 'What is claimed for travel, meals, mileage...: amounts by claim type, awaiting approval, to pay and paid.';
    }

    public function icon(): string
    {
        return 'heroicon-o-receipt-percent';
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $totals = ClaimTotals::amountsByStatus(ClaimTotals::items($filters))
            ->addSelect('claim_items.claim_type_id')
            ->selectRaw('COUNT(*) as items, SUM(claim_items.distance_km) as distance')
            ->groupBy('claim_items.claim_type_id')
            ->get()
            ->keyBy('claim_type_id');
        $types = ClaimType::query()->orderBy('sort')->orderBy('name')->get(['id', 'name', 'is_mileage']);

        $rows = [];
        foreach ($types as $type) {
            $row = $totals->get($type->id);
            if ($row === null) {
                continue;
            }
            $rows[] = [
                'type' => $type->name,
                'items' => (int) $row->items,
                'distance' => $type->is_mileage ? round((float) $row->distance, 1) : null,
                'submitted' => round((float) $row->submitted, 2),
                'approved' => round((float) $row->approved, 2),
                'paid' => round((float) $row->paid, 2),
                'total' => round((float) $row->total, 2),
            ];
        }
        $rows = collect($rows);
        $hasMileage = $rows->whereNotNull('distance')->isNotEmpty();
        $money = fn (string $key, string $label) => ReportColumn::money($key, $label, Claim::CURRENCY);

        return new ReportResult(
            columns: array_values(array_filter([
                ReportColumn::text('type', 'Claim type'),
                ReportColumn::number('items', 'Items'),
                $hasMileage ? ReportColumn::number('distance', 'Distance (km)', 1) : null,
                $money('submitted', 'Awaiting approval'),
                $money('approved', 'To pay'),
                $money('paid', 'Paid'),
                $money('total', 'Total'),
            ])),
            rows: $rows->all(),
            stats: [
                new ReportStat('Total claimed', Claim::formatAmount($rows->sum('total')), $rows->sum('items').' items'),
                new ReportStat('Paid', Claim::formatAmount($rows->sum('paid'))),
                new ReportStat('To pay', Claim::formatAmount($rows->sum('approved')), 'Approved, not paid yet'),
                new ReportStat('Awaiting approval', Claim::formatAmount($rows->sum('submitted'))),
            ],
            chart: ReportChart::bar('Total claimed by type', $rows->pluck('total', 'type')->all(), horizontal: true, unit: Claim::CURRENCY),
            note: ClaimTotals::NOTE,
        );
    }
}
