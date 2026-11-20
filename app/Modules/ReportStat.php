<?php

namespace App\Modules;

/**
 * A headline figure of a report ("Days taken: 124").
 */
final class ReportStat
{
    /**
     * @param  string  $value  already formatted
     * @param  string|null  $detail  a short line under the value
     */
    public function __construct(
        public readonly string $label,
        public readonly string $value,
        public readonly ?string $detail = null,
    ) {}
}
