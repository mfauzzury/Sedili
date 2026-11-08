<?php

namespace App\Modules\Inbox\Models;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{

    protected $table = 'messages';

    protected $fillable = [
        'topic_id',
        'sender_id',
        'content',
        'read_at',
        'receiver_id'
    ];
    protected $casts = [
        'topic_id' => 'integer',
        'sender_id' => 'integer',
        'content' => 'string',
        'read_at' => 'datetime',
        'receiver_id' => 'integer'
    ];
    protected $with = ['sender'];
    /** @return BelongsTo<Employee, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'sender_id');
    }


    /** @return BelongsTo<Topic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'receiver_id');
    }

}