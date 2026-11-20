<?php

namespace App\Modules\Claims\Models;

use App\Models\Concerns\Audited;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Number;

/**
 * An expense claim: several items (date, type, amount, receipt) that the employee wants paid back.
 *
 * Draft -> Submitted -> Approved -> Paid, or Submitted -> Rejected. A submitted claim can be
 * withdrawn back to Draft. Only a Draft can be edited (see ClaimItem).
 *
 * @property int $id
 * @property int $employee_id
 * @property string $title
 * @property string $status
 * @property string $total
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property int|null $actioned_by
 * @property \Illuminate\Support\Carbon|null $actioned_at
 * @property string|null $rejection_reason
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property int|null $paid_by
 * @property string|null $payment_reference
 * @property-read Employee|null $employee
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ClaimItem> $items
 */
class Claim extends Model
{
    use Audited;

    public const CURRENCY = 'MYR';

    public const DRAFT = 'Draft';

    public const SUBMITTED = 'Submitted';

    public const APPROVED = 'Approved';

    public const REJECTED = 'Rejected';

    public const PAID = 'Paid';

    public const STATUSES = [self::DRAFT, self::SUBMITTED, self::APPROVED, self::REJECTED, self::PAID];

    protected $table = 'claims';

    protected $fillable = [
        'employee_id',
        'title',
        'status',
        'total',
        'notes',
        'submitted_at',
        'actioned_by',
        'actioned_at',
        'rejection_reason',
        'paid_at',
        'paid_by',
        'payment_reference',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'submitted_at' => 'datetime',
        'actioned_at' => 'datetime',
        'paid_at' => 'date',
    ];

    protected $attributes = [
        'status' => self::DRAFT,
        'total' => 0,
    ];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /** @return HasMany<ClaimItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ClaimItem::class)->orderBy('date')->orderBy('id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'actioned_by');
    }

    /** @return BelongsTo<Employee, $this> */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'paid_by');
    }

    /**
     * Store the sum of the items. Written with a query, so that it never counts as a change of the
     * claim (no activity log entry, no email).
     */
    public function recalculateTotal(): void
    {
        $total = round((float) ClaimItem::query()->where('claim_id', $this->id)->sum('amount'), 2);
        static::query()->whereKey($this->id)->update(['total' => $total]);
        $this->setRawAttributes([...$this->getAttributes(), 'total' => $total], true);
    }

    public static function formatAmount(float|string|null $amount): string
    {
        return (string) Number::currency((float) $amount, self::CURRENCY);
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            self::SUBMITTED => 'warning',
            self::APPROVED => 'info',
            self::PAID => 'success',
            self::REJECTED => 'danger',
            default => 'gray',
        };
    }
}
