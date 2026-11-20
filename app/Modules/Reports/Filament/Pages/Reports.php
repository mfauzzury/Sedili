<?php

namespace App\Modules\Reports\Filament\Pages;

use App\Models\Department;
use App\Modules\ModuleManager;
use App\Modules\Report;
use App\Modules\ReportColumn;
use App\Modules\ReportFilters;
use App\Modules\ReportResult;
use App\Modules\Reports\Services\ReportCsv;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The reports of the enabled modules. Without a report in the URL: the list of reports, by
 * module. With one (?report=leave.balances): its filters, figures, chart and table, and the
 * CSV export. The filters are kept in the URL, so a report can be bookmarked or shared.
 *
 * @property-read Schema $form
 */
class Reports extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'reports';

    protected static ?string $title = 'Reports';

    protected string $view = 'reports::reports';

    /** Preset periods of the period filter */
    public const PRESETS = [
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_quarter' => 'This quarter',
        'last_quarter' => 'Last quarter',
        'this_year' => 'This year',
        'last_year' => 'Last year',
        'custom' => 'Custom dates',
    ];

    /** Full id of the report shown ("leave.balances"), null for the list of reports */
    #[Url(as: 'report')]
    public ?string $reportId = null;

    #[Url]
    public ?string $period = null;

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    #[Url]
    public ?string $year = null;

    #[Url(as: 'department')]
    public ?string $departmentId = null;

    /** @var array{0: string, 1: ReportResult}|null the result of the current filters, for this request */
    private ?array $cachedResult = null;

    public function mount(): void
    {
        if ($this->reportId !== null && $this->getReport() === null) {
            abort(404);
        }

        if (! array_key_exists((string) $this->period, self::PRESETS)) {
            $this->period = 'this_year';
        }
        if ($this->period !== 'custom' || ! $this->isDate($this->from) || ! $this->isDate($this->to)) {
            [$this->from, $this->to] = self::presetDates($this->period === 'custom' ? 'this_year' : $this->period);
        }
        $this->year = (string) ((int) $this->year ?: now()->year);

        $this->form->fill([
            'period' => $this->period,
            'from' => $this->from,
            'to' => $this->to,
            'year' => $this->year,
            'departmentId' => $this->departmentId,
        ]);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['period', 'from', 'to', 'year', 'departmentId'], true)) {
            // New data, maybe new columns: rebuild the table and go back to its first page
            $this->resetTable();
        }
    }

    public function getReport(): ?Report
    {
        return $this->reportId === null ? null : app(ModuleManager::class)->report($this->reportId);
    }

    /**
     * The reports of the enabled modules, for the list.
     *
     * @return array<string, array{label: string, reports: array<string, Report>}>
     */
    public function getCatalog(): array
    {
        return app(ModuleManager::class)->reports();
    }

    public function getReportUrl(string $module, string $key): string
    {
        return static::getUrl(['report' => "{$module}.{$key}"]);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getReport()?->title() ?? static::$title;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->getReport()?->description() ?: null;
    }

    public function getBreadcrumbs(): array
    {
        $report = $this->getReport();

        return $report === null
            ? [static::getUrl() => 'Reports', 'List']
            : [static::getUrl() => 'Reports', $report->title()];
    }

    public function getFilters(): ReportFilters
    {
        $mode = $this->getReport()?->period() ?? Report::RANGE;
        $today = CarbonImmutable::today();

        [$from, $to] = match ($mode) {
            Report::NONE => [$today, $today],
            Report::YEAR => [
                CarbonImmutable::create((int) $this->year ?: $today->year, 1, 1),
                CarbonImmutable::create((int) $this->year ?: $today->year, 12, 31),
            ],
            default => [
                $this->isDate($this->from) ? CarbonImmutable::parse($this->from) : $today->startOfYear(),
                $this->isDate($this->to) ? CarbonImmutable::parse($this->to) : $today->endOfYear(),
            ],
        };
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $department = (int) $this->departmentId;

        return new ReportFilters($from->startOfDay(), $to->startOfDay(), $department > 0 ? $department : null);
    }

    /**
     * The result of the report for the current filters, built once per request.
     */
    public function getResult(): ?ReportResult
    {
        $report = $this->getReport();
        if ($report === null) {
            return null;
        }

        $filters = $this->getFilters();
        $hash = implode('|', [$this->reportId, $filters->from->toDateString(), $filters->to->toDateString(), $filters->departmentId]);
        if (($this->cachedResult[0] ?? null) !== $hash) {
            $this->cachedResult = [$hash, $report->run($filters)];
        }

        return $this->cachedResult[1];
    }

    public function form(Schema $schema): Schema
    {
        $mode = $this->getReport()?->period() ?? Report::RANGE;

        return $schema->components([
            Grid::make(['default' => 1, 'sm' => 2, 'lg' => 4])->schema([
                Select::make('period')
                    ->options(self::PRESETS)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if ($state !== 'custom') {
                            [$from, $to] = self::presetDates($state);
                            $set('from', $from);
                            $set('to', $to);
                        }
                    })
                    ->visible($mode === Report::RANGE),
                DatePicker::make('from')
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('period', 'custom'))
                    ->required()
                    ->visible($mode === Report::RANGE),
                DatePicker::make('to')
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('period', 'custom'))
                    ->required()
                    ->visible($mode === Report::RANGE),
                Select::make('year')
                    ->options(function (): array {
                        $years = range(now()->year + 1, now()->year - 5);

                        return array_combine($years, $years);
                    })
                    ->selectablePlaceholder(false)
                    ->live()
                    ->visible($mode === Report::YEAR),
                Select::make('departmentId')
                    ->label('Department')
                    ->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder('All departments')
                    ->live(),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        $columns = $this->getResult()->columns ?? [];

        return $table
            ->records(fn (?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator => $this->tableRecords($search, $sortColumn, $sortDirection, $page, $recordsPerPage))
            ->columns(array_map(
                fn (ReportColumn $column) => TextColumn::make($column->key)
                    ->label($column->label)
                    ->formatStateUsing(fn ($state) => $column->format($state))
                    ->alignEnd($column->isNumeric())
                    ->sortable(),
                $columns,
            ))
            ->searchable()
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateIcon('heroicon-o-chart-bar')
            ->emptyStateHeading('Nothing to report')
            ->emptyStateDescription('There is no data for these filters. Try another period or department.');
    }

    /**
     * The rows of the report, searched (any text column), sorted and paginated.
     */
    private function tableRecords(?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $result = $this->getResult();
        $rows = collect($result->rows ?? []);
        // Keys are the ids of the records: start at 1, keep them stable whatever the sorting
        $rows = $rows->values()->keyBy(fn ($row, $index) => $index + 1);

        if (filled($search)) {
            $needle = Str::lower(trim($search));
            $textKeys = collect($result->columns)->reject->isNumeric()->pluck('key')->all();
            $rows = $rows->filter(function (array $row) use ($needle, $textKeys): bool {
                foreach ($textKeys as $key) {
                    if (str_contains(Str::lower((string) ($row[$key] ?? '')), $needle)) {
                        return true;
                    }
                }

                return false;
            });
        }

        if (filled($sortColumn)) {
            $rows = $rows->sortBy(
                fn (array $row) => $row[$sortColumn] ?? null,
                SORT_NATURAL | SORT_FLAG_CASE,
                $sortDirection === 'desc',
            );
        }

        return new LengthAwarePaginator(
            $rows->forPage($page, $recordsPerPage),
            total: $rows->count(),
            perPage: $recordsPerPage,
            currentPage: $page,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => $this->getReport() !== null)
                ->action(fn () => $this->export()),
        ];
    }

    public function export(): ?StreamedResponse
    {
        $report = $this->getReport();
        $result = $this->getResult();
        if ($report === null || $result === null) {
            return null;
        }

        $filters = $this->getFilters();
        $name = Str::slug($report->title()).match ($report->period()) {
            Report::NONE => '-'.$filters->to->toDateString(),
            Report::YEAR => '-'.$filters->year(),
            default => '-'.$filters->from->toDateString().'-to-'.$filters->to->toDateString(),
        };
        $csv = app(ReportCsv::class)->write($result);

        return response()->streamDownload(fn () => print ($csv), "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * First and last day of a preset period, as Y-m-d.
     *
     * @return array{0: string, 1: string}
     */
    public static function presetDates(?string $preset): array
    {
        $today = CarbonImmutable::today();

        [$from, $to] = match ($preset) {
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'this_quarter' => [$today->startOfQuarter(), $today->endOfQuarter()],
            'last_quarter' => [$today->subQuarterNoOverflow()->startOfQuarter(), $today->subQuarterNoOverflow()->endOfQuarter()],
            'last_year' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()],
            default => [$today->startOfYear(), $today->endOfYear()],
        };

        return [$from->toDateString(), $to->toDateString()];
    }

    private function isDate(?string $value): bool
    {
        if (blank($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        try {
            CarbonImmutable::createFromFormat('!Y-m-d', $value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
