<?php

namespace App\Modules\Chatbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chat of one employee with the assistant. Private to the employee: the admin never reads it.
 *
 * @property int $id
 * @property int $employee_id
 * @property string $title
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ChatConversation extends Model
{
    protected $table = 'chatbox_conversations';

    protected $guarded = [];

    /** @return HasMany<ChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->orderBy('id');
    }
}
