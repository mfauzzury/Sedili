<?php

namespace App\Modules\Payroll\Filament\Resources\Payrolls\Pages;

use Filament\Actions\CreateAction;
use App\Modules\Payroll\Filament\Resources\Payrolls\PayrollResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPayrolls extends ListRecords
{
    protected static string $resource = PayrollResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
