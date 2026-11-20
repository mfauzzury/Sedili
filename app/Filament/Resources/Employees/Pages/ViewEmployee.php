<?php

namespace App\Filament\Resources\Employees\Pages;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions;
use App\Filament\Concerns\OpensEditPageWhenAllowed;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployee extends ViewRecord
{
    use OpensEditPageWhenAllowed;

    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make(),
        ];
    }

    // protected function getHeaderWidgets(): array
    // {
    //     return [
    //         \App\Filament\Resources\EmployeeResource\Widgets\StatsOverview::class,
    //     ];
    // }
}