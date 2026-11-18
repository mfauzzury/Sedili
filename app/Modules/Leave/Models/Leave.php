<?php

namespace App\Modules\Leave\Models;

use App\Models\Concerns\Audited;
use App\Models\Employee;
use App\Modules\Leave\Services\LeaveService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $employee_id
 * @property int|null $actioned_by
 * @property string $leave_type
 * @property \Illuminate\Support\Carbon|null $start_date
 * @property \Illuminate\Support\Carbon|null $end_date
 * @property float $days
 * @property string $status
 * @property string|null $rejection_reason
 * @property string|null $notes
 * @property string|null $attachment
 * @property-read Employee|null $employee
 */
class Leave extends Model
{
    use Audited;

    //
    protected $table = 'leave';
    protected $fillable = [
        'employee_id',
        'actioned_by',
        'leave_type',
        'start_date',
        'end_date',
        'days',
        'status',
        'rejection_reason',
        'notes',
        'attachment',
    ];
    protected $casts = [
        'leave_date' => 'datetime:H:i',
        'start_date' => 'date',
        'end_date' => 'date',
        'days' => 'float',
    ];
    protected $appends = [
        'duration',

    ];

    /**
     * Compute the number of days charged to the balance each time a leave is saved.
     */
    protected static function booted(): void
    {
        static::saving(function (Leave $leave) {
            if ($leave->start_date === null || $leave->end_date === null) {
                return;
            }
            $type = LeaveType::where('name', $leave->leave_type)->first();
            $leave->days = app(LeaveService::class)->countDays(
                $leave->start_date,
                $leave->end_date,
                (bool) $type?->count_calendar_days
            );
        });
    }

    /**
     * Number of leave days (working days, see LeaveService).
     */
    public function getDurationAttribute(): float
    {
        return (float) $this->days;
    }


    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
