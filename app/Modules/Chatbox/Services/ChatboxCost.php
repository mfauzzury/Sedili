<?php

namespace App\Modules\Chatbox\Services;

/**
 * What a question costs, to show the admin in System > Chatbox. An estimate at list prices: the real
 * figures are in the Claude Console, and the usage report shows how much of the handbook was read
 * from the cache.
 */
final class ChatboxCost
{
    /**
     * US dollars per million tokens. Haiku's cache price is assumed to be the same share of its
     * input price (5%) as for the other two; the prices for prompts above 100,000 tokens are higher.
     *
     * @var array<string, array{input: float, read: float, output: float}>
     */
    public const PRICES = [
        'claude-opus-5-5' => ['input' => 4.00, 'read' => 0.20, 'output' => 20.00],
        'claude-sonnet-5-5' => ['input' => 2.00, 'read' => 0.10, 'output' => 10.00],
        'claude-haiku-5-5' => ['input' => 0.10, 'read' => 0.005, 'output' => 0.50],
    ];

    /** Writing the handbook to the one-hour cache costs twice the input price */
    public const WRITE_FACTOR = 2.0;

    /** Tokens of a question besides the handbook: the rules, the chat so far, the question */
    public const QUESTION_TOKENS = 500;

    /** Tokens of an average answer */
    public const ANSWER_TOKENS = 400;

    /**
     * @return array{warm: float, cold: float}|null in US dollars; null for an unknown model
     */
    public static function perQuestion(int $handbookTokens, string $model): ?array
    {
        $price = self::PRICES[$model] ?? null;
        if ($price === null) {
            return null;
        }
        $rest = (self::QUESTION_TOKENS * $price['input'] + self::ANSWER_TOKENS * $price['output']) / 1_000_000;

        return [
            'warm' => $handbookTokens * $price['read'] / 1_000_000 + $rest,
            'cold' => $handbookTokens * $price['input'] * self::WRITE_FACTOR / 1_000_000 + $rest,
        ];
    }

    /** Tokens of the rules and of the tool of Insight: read from the cache after the first question */
    public const INSIGHT_PROMPT_TOKENS = 1500;

    /** Tokens of the figures of a report, besides its rows */
    public const INSIGHT_RESULT_TOKENS = 200;

    /** Tokens of a row of a report, as the text given to Claude */
    public const INSIGHT_ROW_TOKENS = 30;

    /** Tokens of the answer: the insight, not the table */
    public const INSIGHT_ANSWER_TOKENS = 350;

    /**
     * What a question to Insight costs when it runs one report with a full table, with the prompt in
     * the cache: two calls (the report is asked for, then the answer is written). Charts and tables
     * are drawn by the server, so they are not in it.
     */
    public static function perInsightQuestion(string $model, int $maxRows): ?float
    {
        $price = self::PRICES[$model] ?? null;
        if ($price === null) {
            return null;
        }
        $result = self::INSIGHT_RESULT_TOKENS + $maxRows * self::INSIGHT_ROW_TOKENS;
        $cached = 2 * self::INSIGHT_PROMPT_TOKENS * $price['read'];
        $input = (2 * 150 + $result) * $price['input'];
        $output = (60 + self::INSIGHT_ANSWER_TOKENS) * $price['output'];

        return ($cached + $input + $output) / 1_000_000;
    }

    public static function format(float $dollars): string
    {
        return $dollars < 0.001 ? 'less than $0.001' : '$'.number_format($dollars, $dollars < 0.1 ? 3 : 2);
    }
}
