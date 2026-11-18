<?php

namespace App\Modules\Claims\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_mileage
 * @property string|null $rate_per_km
 * @property bool $requires_receipt
 * @property bool $is_active
 * @property int $sort
 */
class ClaimType extends Model
{
    use Audited;

    protected $table = 'claim_types';

    protected $fillable = [
        'name',
        'description',
        'is_mileage',
        'rate_per_km',
        'requires_receipt',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'is_mileage' => 'boolean',
        'rate_per_km' => 'decimal:2',
        'requires_receipt' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * @param  Builder<ClaimType>  $query
     * @return Builder<ClaimType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('name');
    }

    public static function requiresReceipt(mixed $id): bool
    {
        return filled($id) && (bool) static::query()->whereKey($id)->value('requires_receipt');
    }

    /** @return HasMany<ClaimItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ClaimItem::class);
    }
}
