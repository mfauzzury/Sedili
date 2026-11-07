<?php

namespace App\Modules\Leave\Filament\Resources\Leaves\Pages;

use App\Modules\Leave\Filament\Resources\Leaves\LeaveResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateLeave extends CreateRecord
{
    protected static string $resource = LeaveResource::class;
    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->getResource()::getUrl('index');
    }
}
