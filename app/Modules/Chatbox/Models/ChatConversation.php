<?php

namespace App\Modules\Chatbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chat of one employee with the assistant. Private to the employee: the admin never reads it.
 *
 * @property int $id
 * @property int $employee_id
 * @property string $kind hr|insight
 * @property string $title
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ChatConversation extends Model
{
    /** A chat of an employee with the assistant of the portal */
    public const HR = 'hr';

    /** A chat of an admin with the reports assistant (Reports > Insight) */
    public const INSIGHT = 'insight';

    protected $table = 'chatbox_conversations';

    protected $guarded = [];

    protected $attributes = ['kind' => self::HR];

    /** @return HasMany<ChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->orderBy('id');
    }
}
