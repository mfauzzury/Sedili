<?php

namespace App\Modules\Inbox\Models;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Topic extends Model
{
    protected $table = "topics";
    protected $fillable = [
        "subject",
        'creator_id',
        'receiver_id',
    ];
    protected $casts = [
        'subject' => 'string',
        'creator_id' => 'integer',
        'receiver_id' => 'integer',
    ];


    /** @return HasMany<Message, $this> */
    public function message(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'creator_id');
    }
}