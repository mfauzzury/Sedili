<?php

namespace App\Modules\Reports\Insight;

use App\Models\Department;
use App\Modules\Chatbox\Services\ChatboxSettings;
use App\Modules\ModuleManager;
use App\Modules\Report;
use App\Modules\ReportFilters;
use App\Modules\ReportResult;
use App\Modules\Reports\Filament\Pages\Reports;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one lookup of Insight: Claude runs a report of the enabled modules, with a period and a
 * department. Claude only names the report and the filters; the figures come from the same report
 * classes as the Reports page, so they are the same, and the chart and the table of the answer are
 * drawn by the server (Claude never writes chart data, which keeps the visuals free of tokens).
 *
 * What goes back to Claude is small: the figures and the first rows as compact text. What is kept for the
 * screen is the report and its filters, never the data.
 */
class ReportTool
{
    public const NAME = 'run_report';

    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ChatboxSettings $settings,
    ) {}

    /**
     * The reports Claude may run: the ones the admin allows, of the modules that are enabled.
     *
     * @return array<string, Report> keyed by id ("leave.balances"), in a fixed order
     */
    public function allowed(): array
    {
        $allowed = array_flip($this->settings->insightReports());
        $reports = [];
        foreach ($this->modules->reports() as $moduleKey => $module) {
            foreach ($module['reports'] as $key => $report) {
                if (isset($allowed["$moduleKey.$key"])) {
                    $reports["$moduleKey.$key"] = $report;
                }
            }
        }
        ksort($reports);

        return $reports;
    }

    /**
     * The tool as Claude takes it. It does not change from one question to the next, so it is cached.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitions(): array
    {
        $reports = $this->allowed();
        if ($reports === []) {
            return [];
        }

        $list = [];
        foreach ($reports as $id => $report) {
            $period = match ($report->period()) {
                Report::YEAR => 'by year (use "year")',
                Report::NONE => 'as of today (no period)',
                default => 'by period (use "period", or "from" and "to")',
            };
            $list[] = "- $id: {$report->title()}. {$report->description()} $period.";
        }

        return [[
            'name' => self::NAME,
            'description' => "Run one report of the company and get its figures and the first rows of its table. The screen shows the admin the chart and the full table by itself. Reports:\n".implode("\n", $list),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'report' => ['type' => 'string', 'enum' => array_keys($reports), 'description' => 'Id of the report'],
                    'period' => ['type' => 'string', 'enum' => array_keys(Reports::PRESETS), 'description' => 'Period of a report by period. Default: this_year. "custom" needs from and to.'],
                    'from' => ['type' => 'string', 'description' => 'First day, Y-m-d, with period custom'],
                    'to' => ['type' => 'string', 'description' => 'Last day, Y-m-d, with period custom'],
                    'year' => ['type' => 'integer', 'description' => 'Year of a report by year. Default: this year.'],
                    'department' => ['type' => 'string', 'description' => 'Name of one department. Default: all departments.'],
                ],
                'required' => ['report'],
            ],
        ]];
    }

    /**
     * Run what Claude asked for.
     *
     * @param  array<string, mixed>  $input  as given by the model
     * @return array{content: string, isError: bool, visual: array<string, mixed>|null}
     */
    public function run(array $input): array
    {
        $id = (string) ($input['report'] ?? '');
        $report = $this->allowed()[$id] ?? null;
        if ($report === null) {
            return $this->error('This report is not available. Use one of the reports listed in the tool.');
        }

        try {
            $department = $this->department($input['department'] ?? null);
            if ($department === false) {
                $names = Department::query()->orderBy('name')->pluck('name')->implode(', ');

                return $this->error('Unknown department. Departments: '.($names ?: 'none').'.');
            }

            $visual = $this->spec($report, $id, $input, $department);
            $filters = $this->filters($visual);
            $result = $report->run($filters);
        } catch (Throwable $e) {
            Log::warning('Insight: a report failed', ['report' => $id, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return $this->error('The report failed. Tell the admin that it could not be run right now.');
        }

        return ['content' => $this->describe($report, $filters, $result, $department), 'isError' => false, 'visual' => $visual];
    }

    /**
     * Filters of a saved visual, as run when the answer was given.
     *
     * @param  array<string, mixed>  $visual
     */
    public function filters(array $visual): ReportFilters
    {
        $from = CarbonImmutable::parse((string) $visual['from'])->startOfDay();
        $to = CarbonImmutable::parse((string) $visual['to'])->startOfDay();
        $department = (int) ($visual['department'] ?? 0);

        return new ReportFilters($from, $to, $department > 0 ? $department : null);
    }

    /**
     * What the screen needs to draw a saved visual again: the report, the result for the same filters and
     * the link to the full report. Null when the report is gone (its module was switched off).
     *
     * @param  array<string, mixed>  $visual
     * @return array{report: Report, result: ReportResult, url: string}|null
     */
    public function resolve(array $visual): ?array
    {
        $report = $this->modules->report((string) ($visual['report'] ?? ''));
        if ($report === null || ! isset($visual['from'], $visual['to'])) {
            return null;
        }

        try {
            $result = $report->run($this->filters($visual));
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return [
            'report' => $report,
            'result' => $result,
            'url' => Reports::getUrl(array_filter([
                'report' => $visual['report'],
                'period' => 'custom',
                'from' => $visual['from'],
                'to' => $visual['to'],
                'year' => $visual['year'] ?? null,
                'department' => $visual['department'] ?? null,
            ], fn ($value) => $value !== null)),
        ];
    }

    /**
     * What is kept to draw the report again: the filters as they were resolved.
     *
     * @param  array<string, mixed>  $input
     * @return array{report: string, from: string, to: string, year: int, department: int|null}
     */
    private function spec(Report $report, string $id, array $input, ?Department $department): array
    {
        $today = CarbonImmutable::today();

        if ($report->period() === Report::NONE) {
            [$from, $to] = [$today, $today];
        } elseif ($report->period() === Report::YEAR) {
            $year = (int) ($input['year'] ?? 0);
            $year = $year >= 2000 && $year <= $today->year + 1 ? $year : $today->year;
            [$from, $to] = [CarbonImmutable::create($year, 1, 1), CarbonImmutable::create($year, 12, 31)];
        } else {
            $preset = (string) ($input['period'] ?? 'this_year');
            $custom = $preset === 'custom' || ! array_key_exists($preset, Reports::PRESETS);
            $from = $to = null;
            if ($custom && $this->isDate($input['from'] ?? null) && $this->isDate($input['to'] ?? null)) {
                [$from, $to] = [CarbonImmutable::parse($input['from']), CarbonImmutable::parse($input['to'])];
            }
            if ($from === null) {
                [$from, $to] = array_map(CarbonImmutable::parse(...), Reports::presetDates($custom ? 'this_year' : $preset));
            }
            if ($from->gt($to)) {
                [$from, $to] = [$to, $from];
            }
        }

        return [
            'report' => $id,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'year' => $from->year,
            'department' => $department?->id,
        ];
    }

    /**
     * The department Claude named: null for none (all departments), false for one that does not exist.
     */
    private function department(mixed $name): Department|false|null
    {
        if (! is_string($name) || trim($name) === '') {
            return null;
        }
        $name = trim($name);

        return Department::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
            ?? (ctype_digit($name) ? Department::query()->find((int) $name) : null)
            ?? false;
    }

    /**
     * The result as compact text for Claude: the figures, then the first rows, one line each.
     */
    private function describe(Report $report, ReportFilters $filters, ReportResult $result, ?Department $department): string
    {
        $lines = [
            'Report: '.$report->title(),
            $report->period() === Report::NONE ? 'As of: '.now()->toDateString() : 'Period: '.$filters->periodLabel(),
            'Department: '.($department->name ?? 'all'),
        ];

        if ($result->stats !== []) {
            $lines[] = '';
            $lines[] = 'Figures:';
            foreach ($result->stats as $stat) {
                $lines[] = "- {$stat->label}: {$stat->value}".($stat->detail ? " ({$stat->detail})" : '');
            }
        }

        $total = count($result->rows);
        if ($total === 0) {
            $lines[] = '';
            $lines[] = 'The table is empty: there is no data for these filters.';
        } else {
            $shown = array_slice($result->rows, 0, $this->settings->insightMaxRows());
            $lines[] = '';
            $lines[] = 'Table ('.(count($shown) < $total ? 'first '.count($shown)." of $total rows" : "$total rows").', in the order of the report):';
            $lines[] = implode(' | ', array_map(fn ($column): string => $column->label, $result->columns));
            foreach ($shown as $row) {
                $lines[] = implode(' | ', array_map(fn ($column): string => $this->cell($column->format($row[$column->key] ?? null)), $result->columns));
            }
            if (count($shown) < $total) {
                $lines[] = ($total - count($shown)).' more rows are not shown to you; the admin sees them on the screen.';
            }
        }
        if ($result->note) {
            $lines[] = '';
            $lines[] = 'Note: '.$result->note;
        }

        return implode("\n", $lines);
    }

    /** A cell on one line: the text of a row (a name, a title) cannot start new lines or columns */
    private function cell(string $value): string
    {
        return trim(str_replace(['|', "\r", "\n"], ['/', ' ', ' '], $value));
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && CarbonImmutable::hasFormat($value, 'Y-m-d');
    }

    /** @return array{content: string, isError: true, visual: null} */
    private function error(string $message): array
    {
        return ['content' => $message, 'isError' => true, 'visual' => null];
    }
}
