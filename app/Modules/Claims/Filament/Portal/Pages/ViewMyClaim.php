<?php

namespace App\Modules\Claims\Filament\Portal\Pages;

use App\Modules\Claims\Filament\ClaimActions;
use App\Modules\Claims\Filament\Portal\MyClaimResource;
use App\Modules\Claims\Filament\ViewsClaim;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMyClaim extends ViewRecord
{
    use ViewsClaim;

    protected static string $resource = MyClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ClaimActions::submit(),
            EditAction::make(),
            ClaimActions::withdraw(),
            ClaimActions::delete(MyClaimResource::getUrl('index')),
        ];
    }
}
