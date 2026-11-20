<?php

namespace App\Modules\Claims\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * One expense of a claim. Its amount is checked on every save: a mileage item is always
 * distance x rate of its type (whatever the form sent), and the total of the claim follows.
 * Items only change while their claim is a Draft.
 *
 * @property int $id
 * @property int $claim_id
 * @property int $claim_type_id
 * @property \Illuminate\Support\Carbon $date
 * @property string $description
 * @property string|null $distance_km
 * @property string|null $rate
 * @property string|float $amount
 * @property string|null $receipt
 * @property-read ClaimType|null $type
 */
class ClaimItem extends Model
{
    use Audited;

    protected $table = 'claim_items';

    protected $fillable = [
        'claim_id',
        'claim_type_id',
        'date',
        'description',
        'distance_km',
        'rate',
        'amount',
        'receipt',
    ];

    protected $casts = [
        'date' => 'date',
        'distance_km' => 'decimal:1',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (ClaimItem $item) {
            self::ensureDraft($item);

            $type = ClaimType::find($item->claim_type_id);
            if ($type?->is_mileage) {
                // The rate is kept from the first save, unless the type changes
                if ($item->rate === null || $item->isDirty('claim_type_id')) {
                    $item->rate = $type->rate_per_km ?? 0;
                }
                $item->amount = round((float) $item->distance_km * (float) $item->rate, 2);
            } else {
                $item->distance_km = null;
                $item->rate = null;
            }

            if ((float) $item->amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'The amount must be more than zero.']);
            }
        });

        static::deleting(fn (ClaimItem $item) => self::ensureDraft($item));

        static::saved(fn (ClaimItem $item) => $item->claim()->first()?->recalculateTotal());
        static::deleted(fn (ClaimItem $item) => $item->claim()->first()?->recalculateTotal());
    }

    private static function ensureDraft(ClaimItem $item): void
    {
        $status = Claim::query()->whereKey($item->claim_id)->value('status');
        if ($status !== null && $status !== Claim::DRAFT) {
            throw ValidationException::withMessages(['items' => 'The items of a claim can only be changed while it is a draft.']);
        }
    }

    /** @return BelongsTo<Claim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    /** @return BelongsTo<ClaimType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ClaimType::class, 'claim_type_id');
    }
}
