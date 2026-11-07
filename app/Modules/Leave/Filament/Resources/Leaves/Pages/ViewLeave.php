<?php

namespace App\Modules\Leave\Filament\Resources\Leaves\Pages;

use Filament\Actions\EditAction;
use App\Modules\Leave\Filament\Resources\Leaves\LeaveResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewLeave extends ViewRecord
{
    protected static string $resource = LeaveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
