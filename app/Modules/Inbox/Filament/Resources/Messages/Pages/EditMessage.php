<?php

namespace App\Modules\Inbox\Filament\Resources\Messages\Pages;

use Filament\Actions\DeleteAction;
use App\Modules\Inbox\Filament\Resources\Messages\MessageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMessage extends EditRecord
{
    protected static string $resource = MessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
