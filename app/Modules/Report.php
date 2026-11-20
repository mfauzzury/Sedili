<?php

namespace App\Modules;

/**
 * A report of a module (see Module::reports()), shown in Reports while both the module and the
 * Reports module are enabled. A report reads the data of its module for the chosen filters and
 * returns figures, an optional chart and a table; the Reports screen does the rest (filters,
 * display, search, sorting, CSV export).
 */
abstract class Report
{
    /** The report covers a date range (from - to) */
    public const RANGE = 'range';

    /** The report covers a calendar year (from = 1 January, to = 31 December) */
    public const YEAR = 'year';

    /** The report is a picture of today: no period filter */
    public const NONE = 'none';

    /**
     * Identifier, unique within the module (e.g. "balances"). The full id is "{module}.{key}".
     */
    abstract public function key(): string;

    abstract public function title(): string;

    /**
     * One sentence on what the report answers, shown in the list of reports.
     */
    public function description(): string
    {
        return '';
    }

    public function icon(): string
    {
        return 'heroicon-o-chart-bar';
    }

    /**
     * Which period filter the report takes: self::RANGE, self::YEAR or self::NONE.
     *
     * @return self::RANGE|self::YEAR|self::NONE
     */
    public function period(): string
    {
        return self::RANGE;
    }

    /**
     * Build the report. Only called for an admin, while the module is enabled.
     */
    abstract public function run(ReportFilters $filters): ReportResult;
}
