<?php

namespace App\Modules\Attendance\Models;

use Carbon\Carbon;
use App\Modules\Attendance\Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Employee;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $employee_id
 * @property \Illuminate\Support\Carbon $date
 * @property \Illuminate\Support\Carbon|null $clock_in
 * @property \Illuminate\Support\Carbon|null $clock_out
 * @property int|null $shift_id
 * @property string|null $remarks
 * @property-read Shift|null $shift
 * @property-read Employee|null $employee
 */
class Attendance extends Model
{
    //
    use HasFactory, Audited;

    protected static function newFactory(): AttendanceFactory
    {
        return AttendanceFactory::new();
    }
    protected $table = 'attendances';
    protected $with = ['shift', 'employee'];

    protected $fillable = [
        'employee_id',
        'date',
        'clock_in',
        'clock_out',

        'shift_id',
        'remarks',
    ];
    protected $casts = [
        'date' => 'date',
        'clock_in' => 'datetime:H:i',
        'clock_out' => 'datetime:H:i',
        'shift_id' => 'integer',

    ];
    protected $appends = [
        'shift_name',
        'hours'
    ];
    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }
    public function getShiftNameAttribute()
    {
        return $this->shift ? $this->shift->name : null;
    }
    public function getHoursAttribute()
    {
        if ($this->clock_in && $this->clock_out) {
            $start = Carbon::parse($this->clock_in);
            $end = Carbon::parse($this->clock_out);
            // A night shift: the clock out is on the next day
            if ($this->shift?->isOvernight() && $end->lt($start)) {
                $end->addDay();
            }
            // diffInMinutes already contains the hours and the remaining minutes
            return round($start->diffInMinutes($end, false) / 60, 2);
        }
        return null;
    }
}
