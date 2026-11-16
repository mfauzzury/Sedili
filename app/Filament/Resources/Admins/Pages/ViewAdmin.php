<?php

namespace App\Filament\Resources\Admins\Pages;

use Filament\Actions\{DeleteAction, EditAction};
use App\Filament\Resources\Admins\AdminResource;

use App\Filament\Concerns\OpensEditPageWhenAllowed;
use Filament\Resources\Pages\ViewRecord;
class ViewAdmin extends ViewRecord
{
    use OpensEditPageWhenAllowed;

    protected static string $resource = AdminResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make(),
        ];
    }
}
