<?php

namespace App\Modules\Attendance\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $start_time H:i:s
 * @property string $end_time H:i:s
 */
class Shift extends Model
{
    //
    protected $table = 'shifts';
    protected $fillable = [
        'name',
        'start_time',
        'end_time',

    ];
    protected $casts = [
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',

    ];
    protected $appends = [
        'duration',
    ];
    /**
     * A night shift ends on the next day (22:00 to 06:00).
     */
    public function isOvernight(): bool
    {
        return Carbon::parse($this->end_time)->format('H:i') < Carbon::parse($this->start_time)->format('H:i');
    }

    public function getDurationAttribute()
    {
        $start = Carbon::parse($this->start_time);
        $end = Carbon::parse($this->end_time);
        if ($this->isOvernight()) {
            $end->addDay();
        }
        return $start->diffInMinutes($end);
    }
}
