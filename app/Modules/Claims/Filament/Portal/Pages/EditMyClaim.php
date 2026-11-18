<?php

namespace App\Modules\Claims\Filament\Portal\Pages;

use App\Modules\Claims\Filament\Portal\MyClaimResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Only a draft of the logged in employee can be opened here (MyClaimResource::canEdit()).
 */
class EditMyClaim extends EditRecord
{
    protected static string $resource = MyClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return MyClaimResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
