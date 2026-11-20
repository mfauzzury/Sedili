<?php

namespace App\Modules\Claims\Filament\Portal\Pages;

use App\Modules\Claims\Filament\ClaimActions;
use App\Modules\Claims\Filament\Portal\TeamClaimResource;
use App\Modules\Claims\Filament\ViewsClaim;
use Filament\Resources\Pages\ViewRecord;

class ViewTeamClaim extends ViewRecord
{
    use ViewsClaim;

    protected static string $resource = TeamClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ClaimActions::approve(),
            ClaimActions::reject(),
        ];
    }
}
