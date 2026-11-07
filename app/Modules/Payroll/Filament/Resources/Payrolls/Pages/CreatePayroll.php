<?php

namespace App\Modules\Payroll\Filament\Resources\Payrolls\Pages;

use App\Modules\Payroll\Filament\Resources\Payrolls\PayrollResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreatePayroll extends CreateRecord
{
    protected static string $resource = PayrollResource::class;
    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->getResource()::getUrl('index');
    }
}
