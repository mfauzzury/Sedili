<?php

namespace App\Modules\Attendance\Filament\Resources\Attendances\Pages;

use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use App\Modules\Attendance\Filament\Resources\Attendances\AttendanceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAttendance extends EditRecord
{
    protected static string $resource = AttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
