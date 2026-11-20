<?php

namespace App\Modules\Claims\Reports;

use App\Modules\Claims\Models\Claim;
use App\Modules\ReportFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The claim items of a period for the reports: items dated in the period, of the claims that were
 * submitted (awaiting approval, approved or paid). Drafts and rejected claims are left out.
 */
final class ClaimTotals
{
    public const STATUSES = [Claim::SUBMITTED, Claim::APPROVED, Claim::PAID];

    public static function items(ReportFilters $filters): Builder
    {
        $query = DB::table('claim_items')
            ->join('claims', 'claims.id', '=', 'claim_items.claim_id')
            ->whereIn('claims.status', self::STATUSES)
            ->tap(fn ($query) => $filters->whereInPeriod($query, 'claim_items.date'));

        return $filters->whereEmployee($query, 'claims.employee_id');
    }

    /**
     * Columns "submitted", "approved" and "paid" (amounts by status) and "total".
     */
    public static function amountsByStatus(Builder $query): Builder
    {
        return $query->selectRaw(
            'SUM(CASE WHEN claims.status = ? THEN claim_items.amount ELSE 0 END) as submitted, '
            .'SUM(CASE WHEN claims.status = ? THEN claim_items.amount ELSE 0 END) as approved, '
            .'SUM(CASE WHEN claims.status = ? THEN claim_items.amount ELSE 0 END) as paid, '
            .'SUM(claim_items.amount) as total',
            self::STATUSES,
        );
    }

    public const NOTE = 'Items dated in the period, of the claims awaiting approval, approved or paid. Drafts and rejected claims are not counted.';
}
