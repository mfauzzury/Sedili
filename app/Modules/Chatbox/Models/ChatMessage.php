<?php

namespace App\Modules\Chatbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $conversation_id
 * @property string $role user|assistant
 * @property string $content
 * @property list<array<string, mixed>>|null $visuals the reports shown under the answer (Insight)
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $cache_read_tokens
 */
class ChatMessage extends Model
{
    public const USER = 'user';

    public const ASSISTANT = 'assistant';

    protected $table = 'chatbox_messages';

    protected $guarded = [];

    protected $casts = ['visuals' => 'array'];

    /**
     * The message as safe HTML: Markdown with the HTML of the text escaped, no unsafe links and no images.
     * An image is fetched by the browser by itself, and its address could carry what Claude has seen
     * (the text it reads, such as a task description, can steer what it writes) to another server.
     */
    public function html(): string
    {
        $html = Str::markdown($this->content, ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        return preg_replace('/<img\b[^>]*>/i', '', $html) ?? '';
    }

    /** @return BelongsTo<ChatConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }
}
