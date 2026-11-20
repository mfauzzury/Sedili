<?php

namespace App\Modules\Reports\Filament\Widgets;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Js;
use Illuminate\Support\Number;

/**
 * The chart of a report (App\Modules\ReportChart), embedded in the Reports page. It receives the
 * data from the page; the page gives it a new key when the data change, so that it is redrawn.
 */
class ReportChartWidget extends ChartWidget
{
    /** @var array{type: string, title: string, labels: list<string>, values: list<int|float>, horizontal: bool, unit: string|null} */
    public array $chart;

    protected ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return $this->chart['title'];
    }

    protected function getType(): string
    {
        return $this->chart['type'];
    }

    protected function getData(): array
    {
        return [
            'labels' => $this->chart['labels'],
            'datasets' => [[
                'label' => $this->chart['title'],
                'data' => $this->chart['values'],
                // Thin bars with rounded ends; the colour is the primary colour of the panel
                'borderRadius' => 4,
                'maxBarThickness' => 28,
                'borderWidth' => $this->chart['type'] === 'line' ? 2 : 0,
                'pointRadius' => count($this->chart['values']) > 40 ? 0 : 4,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        $valueAxis = $this->chart['horizontal'] ? 'x' : 'y';
        $categoryAxis = $this->chart['horizontal'] ? 'y' : 'x';
        // Js::from: a literal that is safe inside the HTML attribute the options are rendered in
        $unit = Js::from($this->chart['unit'] ? ' '.$this->chart['unit'] : '');

        // Single series: no legend (the heading names it); grid lines only across the values
        return RawJs::make(<<<JS
            {
                indexAxis: '{$categoryAxis}',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => ' ' + context.formattedValue + {$unit},
                        },
                    },
                },
                scales: {
                    {$valueAxis}: { beginAtZero: true, grid: { display: true }, border: { display: false } },
                    {$categoryAxis}: { grid: { display: false } },
                },
            }
        JS);
    }

    /**
     * Read by screen readers instead of the canvas.
     */
    public function getChartAssistiveContent(): string
    {
        $parts = [];
        foreach ($this->chart['labels'] as $i => $label) {
            $parts[] = $label.': '.Number::format((float) ($this->chart['values'][$i] ?? 0), maxPrecision: 2);
        }

        return $this->chart['title'].'. '.implode(', ', $parts);
    }
}
