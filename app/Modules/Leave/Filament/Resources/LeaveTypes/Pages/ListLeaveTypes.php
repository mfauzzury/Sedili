<?php

namespace App\Modules\Leave\Filament\Resources\LeaveTypes\Pages;

use App\Modules\Leave\Filament\Resources\LeaveTypes\LeaveTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLeaveTypes extends ListRecords
{
    protected static string $resource = LeaveTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
