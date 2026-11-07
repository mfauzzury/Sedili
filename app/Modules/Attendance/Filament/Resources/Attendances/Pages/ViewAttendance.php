<?php

namespace App\Modules\Attendance\Filament\Resources\Attendances\Pages;

use Filament\Actions\EditAction;
use App\Modules\Attendance\Filament\Resources\Attendances\AttendanceResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewAttendance extends ViewRecord
{
    protected static string $resource = AttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
