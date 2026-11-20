<?php

namespace App\Modules\Tasks\Models;

use App\Models\Employee;
use App\Modules\Tasks\Filament\Pages\TaskBoard;
use Illuminate\Database\Eloquent\Model;
use Filament\Notifications\Notification;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property int|null $sort_order
 * @property int|null $assignee_id
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property-read Employee|null $assignee
 */
class Task extends Model
{
    //
    protected $fillable = [
        'title',
        'description',
        'status',
        'sort_order',
        'assignee_id',

        'due_date',
        'position'
    ];

    protected $casts = [
        'title' => 'string',
        'description' => 'string',
        'status' => 'string',
        'sort_order' => 'integer',
        'assignee_id' => 'integer',
        'due_date' => 'datetime',
        'position' => 'integer',
    ];
    protected $table = 'tasks';
    protected $appends = ['date', 'email'];

    /** @return BelongsTo<Employee, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_id');
    }


    public function getDateAttribute()
    {
        return $this->due_date?->format('d-M-Y');
    }
    public function getEmailAttribute()
    {
        return $this->assignee?->email;
    }

}
