<?php

namespace App\Modules\Reports;

use App\Modules\Module;
use App\Modules\Reports\Filament\Pages\Reports;
use App\Modules\Reports\Filament\Widgets\ReportChartWidget;
use App\Modules\Reports\Reports\HeadcountReport;

class ReportsModule extends Module
{
    public function key(): string
    {
        return 'reports';
    }

    public function label(): string
    {
        return 'Reports';
    }

    public function description(): string
    {
        return 'Reports of every enabled module (leave, claims, attendance, payroll, tasks...) and of the headcount, by period and department, with a CSV export.';
    }

    public function filament(string $panelId): array
    {
        return $panelId === 'admin'
            ? ['pages' => [Reports::class], 'livewire' => [ReportChartWidget::class]]
            : [];
    }

    /**
     * Employees are core: their report belongs to this module.
     */
    public function reports(): array
    {
        return [new HeadcountReport];
    }
}
