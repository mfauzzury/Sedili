<?php

namespace App\Modules\Chatbox\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A document the assistant answers from (a handbook, a policy, a guide...): the uploaded file, the
 * text read from it and the label the admin gave it. The enabled ones are all given to Claude.
 *
 * @property int $id
 * @property string $name file name on the private disk
 * @property string|null $label
 * @property string $kind pdf|docx
 * @property bool $enabled
 * @property string $path
 * @property int $pages
 * @property int $characters size of the text
 * @property int|null $uploaded_by
 * @property string $text
 * @property string|null $preview the start of the text: only on a document loaded with overview()
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class ChatDocument extends Model
{
    /** Rough size of a token in characters, to estimate what the text costs */
    public const CHARS_PER_TOKEN = 4;

    protected $table = 'chatbox_documents';

    protected $guarded = [];

    protected $casts = ['enabled' => 'boolean'];

    /** The name the admin gave it, or its file name */
    public function displayLabel(): string
    {
        return $this->label !== null && $this->label !== '' ? $this->label : pathinfo($this->fileName(), PATHINFO_FILENAME);
    }

    /** The name of the uploaded file, without the prefix that makes it unique on the disk */
    public function fileName(): string
    {
        return Str::after($this->name, '__');
    }

    /** What the text costs in tokens, roughly: to give the admin an idea of the price of a question */
    public function estimatedTokens(): int
    {
        return (int) ceil($this->characters / self::CHARS_PER_TOKEN);
    }

    /**
     * Without the text (it can be long): the start of it is in "preview", its size in "characters".
     *
     * @param  Builder<ChatDocument>  $query
     * @return Builder<ChatDocument>
     */
    public function scopeOverview(Builder $query, int $previewCharacters = 2000): Builder
    {
        return $query
            ->select(['id', 'name', 'label', 'kind', 'enabled', 'path', 'pages', 'characters', 'uploaded_by', 'created_at'])
            ->selectRaw('SUBSTR(text, 1, ?) as preview', [$previewCharacters]);
    }

    /**
     * @param  Builder<ChatDocument>  $query
     * @return Builder<ChatDocument>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * The documents given to Claude, always in the same order: the order is part of what is cached.
     *
     * @return Collection<int, ChatDocument>
     */
    public static function active(): Collection
    {
        return self::query()->enabled()->orderBy('id')->get();
    }

    public static function hasActive(): bool
    {
        return self::query()->enabled()->exists();
    }
}
