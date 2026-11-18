<?php

namespace App\Modules\Claims\Filament\Portal\Pages;

use App\Modules\Claims\Filament\Portal\TeamClaimResource;
use Filament\Resources\Pages\ListRecords;

class ListTeamClaims extends ListRecords
{
    protected static string $resource = TeamClaimResource::class;
}
