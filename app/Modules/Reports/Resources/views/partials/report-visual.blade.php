{{-- One report under an answer of Insight: its figures, chart and first rows. Drawn by the server from the report and its filters. --}}
@php
    $report = $visual['report'];
    $result = $visual['result'];
    $rows = array_slice($result->rows, 0, \App\Modules\Reports\Filament\Pages\Insight::TABLE_ROWS);
@endphp
<div class="mt-3 space-y-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <div class="flex items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-2">
            <x-filament::icon :icon="$report->icon()" class="h-4 w-4 shrink-0 text-primary-600 dark:text-primary-400" />
            <span class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $report->title() }}</span>
        </div>
        <a href="{{ $visual['url'] }}" wire:navigate class="shrink-0 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">Open full report</a>
    </div>

    @if ($result->stats)
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            @foreach ($result->stats as $stat)
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                    <div class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $stat->label }}</div>
                    <div class="text-lg font-semibold tracking-tight text-gray-950 dark:text-white">{{ $stat->value }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($mode === 'full' && $result->chart && ! $result->chart->isEmpty())
        <div class="report-chart">
            @livewire(\App\Modules\Reports\Filament\Widgets\ReportChartWidget::class, ['chart' => $result->chart->toArray()], key("insight-chart-{$messageId}-{$index}-".md5(json_encode($result->chart->toArray()))))
        </div>
    @endif

    @if ($rows)
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                        @foreach ($result->columns as $column)
                            <th @class(['whitespace-nowrap px-2 py-1.5 font-medium', 'text-right' => $column->isNumeric()])>{{ $column->label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-gray-950 dark:divide-white/5 dark:text-white">
                    @foreach ($rows as $row)
                        <tr>
                            @foreach ($result->columns as $column)
                                <td @class(['px-2 py-1.5', 'text-right tabular-nums' => $column->isNumeric()])>{{ $column->format($row[$column->key] ?? null) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if (count($result->rows) > count($rows))
            <p class="text-xs text-gray-500 dark:text-gray-400">First {{ count($rows) }} of {{ count($result->rows) }} rows. The full table is in the report.</p>
        @endif
    @else
        <p class="text-xs text-gray-500 dark:text-gray-400">There is no data for these filters.</p>
    @endif
</div>
