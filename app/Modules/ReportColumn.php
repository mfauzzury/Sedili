<?php

namespace App\Modules;

use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * A column of a report table. The rows hold raw values (numbers, Y-m-d dates), so that the table
 * sorts them correctly and the CSV export can be used in a spreadsheet; the column formats them.
 */
final class ReportColumn
{
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly int $decimals = 0,
        public readonly ?string $currency = null,
    ) {}

    public static function text(string $key, string $label): self
    {
        return new self($key, $label, 'text');
    }

    public static function number(string $key, string $label, int $decimals = 0): self
    {
        return new self($key, $label, 'number', $decimals);
    }

    /**
     * An amount, displayed with the currency of the module (e.g. MYR).
     */
    public static function money(string $key, string $label, string $currency): self
    {
        return new self($key, $label, 'money', 2, $currency);
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, 'date');
    }

    public function isNumeric(): bool
    {
        return in_array($this->type, ['number', 'money'], true);
    }

    /**
     * The value as displayed on screen.
     */
    public function format(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($this->type) {
            'number' => (string) Number::format((float) $value, $this->decimals),
            'money' => (string) Number::currency((float) $value, $this->currency ?? 'USD'),
            'date' => Carbon::parse($value)->format('j M Y'),
            default => (string) $value,
        };
    }

    /**
     * The value as written in the CSV export: numbers without thousands separators.
     */
    public function export(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return match ($this->type) {
            'number', 'money' => number_format((float) $value, $this->decimals, '.', ''),
            default => (string) $value,
        };
    }
}
