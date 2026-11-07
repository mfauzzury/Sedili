<?php

namespace App\Modules\Leave\Filament\Resources\Leaves\Pages;

use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use App\Modules\Leave\Filament\Resources\Leaves\LeaveResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLeave extends EditRecord
{
    protected static string $resource = LeaveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
