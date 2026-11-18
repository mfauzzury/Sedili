<?php

namespace App\Modules\Claims\Filament\Portal\Pages;

use App\Modules\Claims\Filament\Portal\MyClaimResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMyClaims extends ListRecords
{
    protected static string $resource = MyClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New claim'),
        ];
    }
}
