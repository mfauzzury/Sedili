<?php

namespace App\Modules\Chatbox\Services;

/**
 * One answer of Claude: the text, and what it asked for (lookups) before it can finish. A question is
 * answered in one or more turns: after each lookup Claude continues in a new turn.
 */
final class ChatTurn
{
    /**
     * @param  list<array<string, mixed>>  $blocks  what Claude wrote, to give back as it is when continuing after a lookup
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $toolCalls  the lookups Claude asks for
     */
    public function __construct(
        public readonly string $text,
        public readonly array $blocks = [],
        public readonly array $toolCalls = [],
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly bool $refused = false,
    ) {}
}
