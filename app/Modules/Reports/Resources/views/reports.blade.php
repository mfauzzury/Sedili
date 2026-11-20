<x-filament-panels::page>
    @if ($this->getReport() === null)
        {{-- The list of reports, by module --}}
        @forelse ($this->getCatalog() as $moduleKey => $module)
            <section class="space-y-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $module['label'] }}</h2>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($module['reports'] as $key => $report)
                        <a
                            href="{{ $this->getReportUrl($moduleKey, $key) }}"
                            wire:navigate
                            class="group flex gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 transition hover:ring-primary-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:bg-gray-900 dark:ring-white/10 dark:hover:ring-primary-400"
                        >
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                                <x-filament::icon :icon="$report->icon()" class="h-5 w-5" />
                            </span>
                            <span class="min-w-0">
                                <span class="block font-semibold text-gray-950 group-hover:text-primary-600 dark:text-white dark:group-hover:text-primary-400">{{ $report->title() }}</span>
                                <span class="mt-0.5 block text-sm text-gray-500 dark:text-gray-400">{{ $report->description() }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @empty
            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">No report available: enable modules with reports (Leave, Claims, Attendance...) in System &gt; Modules.</p>
            </x-filament::section>
        @endforelse
    @else
        @php($result = $this->getResult())

        <x-filament::section compact>
            {{ $this->form }}
            @if ($this->getReport()->period() === \App\Modules\Report::NONE)
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">As of today, {{ now()->format('j M Y') }}.</p>
            @endif
        </x-filament::section>

        @if ($result->stats)
            <div @class([
                'grid gap-4 grid-cols-2',
                'lg:grid-cols-3' => count($result->stats) === 3,
                'lg:grid-cols-4' => count($result->stats) >= 4,
            ])>
                @foreach ($result->stats as $stat)
                    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $stat->label }}</div>
                        <div class="mt-1 text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $stat->value }}</div>
                        @if ($stat->detail)
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $stat->detail }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($result->chart && ! $result->chart->isEmpty())
            {{-- A new key when the data change: the widget is mounted again with them --}}
            <div class="report-chart">
                @livewire(\App\Modules\Reports\Filament\Widgets\ReportChartWidget::class, ['chart' => $result->chart->toArray()], key('chart-'.md5(json_encode($result->chart->toArray()))))
            </div>
        @endif

        {{ $this->table }}

        @if ($result->note)
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $result->note }}</p>
        @endif
    @endif
</x-filament-panels::page>
