<?php

namespace App\Modules\Chatbox\Services;

use Anthropic\Beta\Messages\BetaInputJSONDelta;
use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaRawContentBlockStartEvent;
use Anthropic\Beta\Messages\BetaRawMessageDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageStartEvent;
use Anthropic\Beta\Messages\BetaRedactedThinkingBlock;
use Anthropic\Beta\Messages\BetaSignatureDelta;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Beta\Messages\BetaThinkingBlock;
use Anthropic\Beta\Messages\BetaThinkingDelta;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\OverloadedException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Modules\Chatbox\Exceptions\ChatboxException;
use Illuminate\Support\Facades\Log;

/**
 * Claude through the official Anthropic PHP SDK, with the API key saved in System > Chatbox.
 */
class AnthropicGateway implements ClaudeGateway
{
    private const MAX_TOKENS = 4000;

    private ?Client $client = null;

    public function __construct(private readonly ChatboxSettings $settings) {}

    public function stream(string $model, array $system, array $messages, array $tools, callable $onDelta, ?int $maxTokens = null): ChatTurn
    {
        $text = '';
        $blocks = [];
        $input = $output = $cacheRead = 0;
        $stopReason = null;

        try {
            // Chat answers do not need deep thinking: low effort. Without lookups, if Claude declines for a
            // policy reason the API retries on its default fallback model by itself. With lookups it does
            // not: a fallback turn would have to be given back as it is when the conversation goes on.
            $stream = $this->client()->beta->messages->createStream(
                maxTokens: $maxTokens ?? self::MAX_TOKENS,
                messages: $this->wireMessages($messages),
                model: $model,
                outputConfig: ['effort' => 'low'],
                system: $this->wireSystem($system),
                tools: $tools === [] ? null : $this->wireTools($tools),
                betas: $tools === [] ? ['server-side-fallback-2026-07-01'] : null,
                fallbacks: $tools === [] ? 'default' : null,
            );

            foreach ($stream as $event) {
                if ($event instanceof BetaRawMessageStartEvent) {
                    $usage = $event->message->usage;
                    $input = $usage->inputTokens + ($usage->cacheCreationInputTokens ?? 0);
                    $cacheRead = $usage->cacheReadInputTokens ?? 0;
                } elseif ($event instanceof BetaRawContentBlockStartEvent) {
                    $started = $this->startBlock($event->contentBlock);
                    $blocks[$event->index] = $started;
                    if (($started['type'] ?? null) === 'text' && $started['text'] !== '') {
                        $text .= $started['text'];
                        $onDelta($started['text']);
                    }
                } elseif ($event instanceof BetaRawContentBlockDeltaEvent) {
                    $delta = $event->delta;
                    $block = &$blocks[$event->index];
                    if ($delta instanceof BetaTextDelta && is_array($block)) {
                        $block['text'] .= $delta->text;
                        $text .= $delta->text;
                        $onDelta($delta->text);
                    } elseif ($delta instanceof BetaInputJSONDelta && is_array($block)) {
                        $block['json'] .= $delta->partialJSON;
                    } elseif ($delta instanceof BetaThinkingDelta && is_array($block)) {
                        $block['thinking'] .= $delta->thinking;
                    } elseif ($delta instanceof BetaSignatureDelta && is_array($block)) {
                        $block['signature'] = $delta->signature;
                    }
                    unset($block);
                } elseif ($event instanceof BetaRawMessageDeltaEvent) {
                    $output = $event->usage->outputTokens;
                    $stopReason = $event->delta->stopReason;
                }
            }
        } catch (AnthropicException $e) {
            throw new ChatboxException($this->messageFor($e), previous: $e);
        }

        $blocks = $this->finish($blocks);
        $calls = $stopReason === 'tool_use'
            ? array_values(array_map(
                fn (array $block): array => ['id' => $block['id'], 'name' => $block['name'], 'input' => $block['input']],
                array_filter($blocks, fn (array $block): bool => $block['type'] === 'tool_use'),
            ))
            : [];

        return new ChatTurn($text, $blocks, $calls, $input, $output, $cacheRead, $stopReason === 'refusal');
    }

    /**
     * The start of a block as a plain array that can be given back to Claude. Blocks we have no use for
     * (a fallback notice...) are left out.
     *
     * @return array<string, mixed>|null
     */
    private function startBlock(object $block): ?array
    {
        return match (true) {
            $block instanceof BetaTextBlock => ['type' => 'text', 'text' => $block->text],
            $block instanceof BetaThinkingBlock => ['type' => 'thinking', 'thinking' => $block->thinking, 'signature' => $block->signature],
            $block instanceof BetaRedactedThinkingBlock => ['type' => 'redacted_thinking', 'data' => $block->data],
            $block instanceof BetaToolUseBlock => ['type' => 'tool_use', 'id' => $block->id, 'name' => $block->name, 'input' => [], 'json' => ''],
            default => null,
        };
    }

    /**
     * The blocks in order, with the input of each lookup read from the JSON that was streamed.
     *
     * @param  array<int, array<string, mixed>|null>  $blocks
     * @return list<array<string, mixed>>
     */
    private function finish(array $blocks): array
    {
        ksort($blocks);
        $done = [];
        foreach ($blocks as $block) {
            if ($block === null) {
                continue;
            }
            if ($block['type'] === 'tool_use') {
                $decoded = $block['json'] === '' ? [] : json_decode($block['json'], true);
                $block['input'] = is_array($decoded) ? $decoded : [];
                unset($block['json']);
            }
            $done[] = $block;
        }

        return $done;
    }

    /**
     * The system blocks as the SDK takes them: its keys are camelCase.
     *
     * @param  list<array{type: string, text: string, cache_control?: array<string, string>}>  $system
     * @return list<array<string, mixed>>
     */
    private function wireSystem(array $system): array
    {
        return array_map(function (array $block): array {
            if (isset($block['cache_control'])) {
                $block['cacheControl'] = $block['cache_control'];
                unset($block['cache_control']);
            }

            return $block;
        }, $system);
    }

    /**
     * The tools as the SDK takes them: its keys are camelCase.
     *
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $tools
     * @return list<array<string, mixed>>
     */
    private function wireTools(array $tools): array
    {
        return array_map(fn (array $tool): array => [
            'name' => $tool['name'],
            'description' => $tool['description'],
            'inputSchema' => $tool['input_schema'],
        ], $tools);
    }

    /**
     * The messages as the SDK takes them: its keys are camelCase.
     *
     * @param  list<array{role: string, content: string|list<array<string, mixed>>}>  $messages
     * @return list<array{role: string, content: string|list<array<string, mixed>>}>
     */
    private function wireMessages(array $messages): array
    {
        foreach ($messages as &$message) {
            if (! is_array($message['content'])) {
                continue;
            }
            foreach ($message['content'] as &$block) {
                if ($block['type'] === 'tool_result') {
                    $block = ['type' => 'tool_result', 'toolUseID' => $block['tool_use_id'], 'content' => $block['content'], 'isError' => $block['is_error'] ?? false];
                }
            }
            unset($block);
        }
        unset($message);

        return $messages;
    }

    public function checkConnection(string $model): void
    {
        try {
            $this->client()->models->retrieve($model);
        } catch (AuthenticationException|PermissionDeniedException $e) {
            throw new ChatboxException('Claude refused the API key ('.$e->status.'). Check that it is correct and active.', previous: $e);
        } catch (APIStatusException $e) {
            throw new ChatboxException('Claude answered with an error ('.$e->status.'): '.$e->getMessage(), previous: $e);
        } catch (AnthropicException $e) {
            throw new ChatboxException('Could not reach Claude: '.$e->getMessage(), previous: $e);
        }
    }

    /** Built once: the key is decrypted and the client made for the first call only, not for each lookup of a question */
    private function client(): Client
    {
        if ($this->client === null) {
            $key = $this->settings->apiKey();
            if ($key === null) {
                throw new ChatboxException('The assistant is not set up yet.');
            }
            $this->client = new Client(apiKey: $key);
        }

        return $this->client;
    }

    /**
     * The sentence the employee sees for a failed request. The details go to the log.
     */
    private function messageFor(AnthropicException $e): string
    {
        Log::warning('Chatbox: the request to Claude failed', ['exception' => $e::class, 'message' => $e->getMessage()]);

        return match (true) {
            $e instanceof AuthenticationException, $e instanceof PermissionDeniedException => 'The assistant cannot sign in to Claude at the moment. Please tell HR.',
            $e instanceof RateLimitException, $e instanceof OverloadedException => 'The assistant is very busy right now. Please try again in a minute.',
            $e instanceof APIConnectionException => 'The assistant could not be reached. Check your connection and try again.',
            $e instanceof APIStatusException => 'The assistant ran into a problem and could not answer. Please try again later.',
            default => 'The assistant could not answer. Please try again later.',
        };
    }
}
