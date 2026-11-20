<?php

namespace App\Modules;

/**
 * The chart of a report: one series of values, one per label.
 *
 * One series only, drawn in the primary colour: a report that needs to compare two measures
 * shows them in its table. Use a bar chart for categories (horizontal when the labels are long
 * names) and a line chart for a value over time.
 */
final class ReportChart
{
    /**
     * @param  'bar'|'line'  $type
     * @param  string  $title  what is measured ("Days taken by leave type")
     * @param  list<string>  $labels
     * @param  list<int|float>  $values
     * @param  bool  $horizontal  bars from the left, for long labels
     * @param  string|null  $unit  added to the values in the tooltip ("days")
     */
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly array $labels,
        public readonly array $values,
        public readonly bool $horizontal = false,
        public readonly ?string $unit = null,
    ) {}

    public static function bar(string $title, array $values, bool $horizontal = false, ?string $unit = null): self
    {
        return new self('bar', $title, array_map('strval', array_keys($values)), array_values($values), $horizontal, $unit);
    }

    public static function line(string $title, array $values, ?string $unit = null): self
    {
        return new self('line', $title, array_map('strval', array_keys($values)), array_values($values), false, $unit);
    }

    public function isEmpty(): bool
    {
        return array_sum($this->values) == 0;
    }

    /**
     * For the chart widget (a Livewire property).
     *
     * @return array{type: string, title: string, labels: list<string>, values: list<int|float>, horizontal: bool, unit: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'labels' => $this->labels,
            'values' => $this->values,
            'horizontal' => $this->horizontal,
            'unit' => $this->unit,
        ];
    }
}
