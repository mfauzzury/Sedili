<?php

namespace App\Modules\Leave\Filament\Resources\Holidays\Pages;

use App\Modules\Leave\Filament\Resources\Holidays\HolidayResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHolidays extends ListRecords
{
    protected static string $resource = HolidayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
