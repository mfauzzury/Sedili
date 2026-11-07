<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    protected $fillable = [
        'name',
        'days_per_year',
        'tiers',
        'gender_restriction',
        'count_calendar_days',
        'is_active',
    ];

    protected $casts = [
        'days_per_year' => 'float',
        'tiers' => 'array',
        'count_calendar_days' => 'boolean',
        'is_active' => 'boolean',
    ];
}
