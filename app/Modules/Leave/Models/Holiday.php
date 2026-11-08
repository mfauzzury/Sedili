<?php

namespace App\Modules\Leave\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use Audited;

    protected $fillable = [
        'date',
        'name',
    ];

    protected $casts = [
        'date' => 'date',
    ];
}
