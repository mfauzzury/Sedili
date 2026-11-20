<?php

namespace App\Modules\Chatbox\Services;

use App\Modules\Chatbox\Exceptions\ChatboxException;

/**
 * The calls to Claude. One implementation talks to the API (AnthropicGateway); the tests replace it.
 */
interface ClaudeGateway
{
    /**
     * Everything given to the gateway is in the neutral (API) form, snake_case: the gateway alone knows how
     * the SDK wants it.
     *
     * Ask Claude for one turn and hand over its text piece by piece while it is written. The messages
     * have text as content, or lists of blocks ("text", "thinking", "tool_use", and "tool_result" with
     * "tool_use_id", "content" and "is_error") when the conversation goes on after a lookup.
     *
     * @param  list<array{type: string, text: string, cache_control?: array<string, string>}>  $system
     * @param  list<array{role: string, content: string|list<array<string, mixed>>}>  $messages
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $tools
     * @param  callable(string): void  $onDelta
     *
     * @throws ChatboxException with a message for the employee
     */
    public function stream(string $model, array $system, array $messages, array $tools, callable $onDelta): ChatTurn;

    /**
     * Check the saved key and model without spending tokens.
     *
     * @throws ChatboxException with a message for the admin
     */
    public function checkConnection(string $model): void;
}
