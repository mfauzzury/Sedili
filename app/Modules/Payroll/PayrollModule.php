<?php

namespace App\Modules\Payroll;

use App\Modules\Module;
use App\Modules\Payroll\Filament\Resources\Payrolls\PayrollResource;
use App\Modules\Payroll\Reports\PayrollSummaryReport;

class PayrollModule extends Module
{
    public function key(): string
    {
        return 'payroll';
    }

    public function label(): string
    {
        return 'Payroll';
    }

    public function description(): string
    {
        return 'Monthly payroll records with allowances, deductions and bonuses.';
    }

    public function filament(string $panelId): array
    {
        return $panelId === 'admin' ? ['resources' => [PayrollResource::class]] : [];
    }

    public function reports(): array
    {
        return [new PayrollSummaryReport];
    }
}
