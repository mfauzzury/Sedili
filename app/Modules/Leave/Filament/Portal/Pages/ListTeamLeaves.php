<?php

namespace App\Modules\Leave\Filament\Portal\Pages;

use App\Modules\Leave\Filament\Portal\TeamLeaveResource;
use Filament\Resources\Pages\ListRecords;

class ListTeamLeaves extends ListRecords
{
    protected static string $resource = TeamLeaveResource::class;
}
