<?php

namespace App\Modules\Claims\Filament\Resources\Claims\Pages;

use App\Modules\Claims\Filament\ClaimActions;
use App\Modules\Claims\Filament\Resources\Claims\ClaimResource;
use App\Modules\Claims\Filament\ViewsClaim;
use Filament\Resources\Pages\ViewRecord;

class ViewClaim extends ViewRecord
{
    use ViewsClaim;

    protected static string $resource = ClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ClaimActions::approve(),
            ClaimActions::reject(),
            ClaimActions::markPaid(),
        ];
    }
}
