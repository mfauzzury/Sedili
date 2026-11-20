<?php

namespace App\Modules;

/**
 * What a report returns: headline figures, an optional chart and a table.
 */
final class ReportResult
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<array<string, scalar|null>>  $rows  one value per column key
     * @param  list<ReportStat>  $stats  headline figures shown above the table (3 or 4 work best)
     * @param  string|null  $note  how the figures are counted, shown under the table
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $stats = [],
        public readonly ?ReportChart $chart = null,
        public readonly ?string $note = null,
    ) {}
}
