<?php

namespace App\Modules\Attendance\Filament\Resources\Attendances\Pages;

use Filament\Actions\CreateAction;
use App\Modules\Attendance\Filament\Resources\Attendances\AttendanceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAttendances extends ListRecords
{
    protected static string $resource = AttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
