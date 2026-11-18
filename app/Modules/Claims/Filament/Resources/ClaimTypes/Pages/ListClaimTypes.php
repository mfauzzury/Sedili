<?php

namespace App\Modules\Claims\Filament\Resources\ClaimTypes\Pages;

use App\Modules\Claims\Filament\Resources\ClaimTypes\ClaimTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClaimTypes extends ListRecords
{
    protected static string $resource = ClaimTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
