<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    //

    protected $fillable = [
        'title',
        'department_id',
        'code',
        'description',
        'salary',
    ];
    protected $table = 'positions';
    protected $with = ['department'];
    protected $casts = [
        'salary' => 'decimal:2',
    ];
    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
    /** @return HasMany<Employee, $this> */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
