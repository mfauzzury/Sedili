<?php

namespace App\Modules\Attendance\Filament\Resources\Attendances\Pages;

use App\Modules\Attendance\Filament\Resources\Attendances\AttendanceResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateAttendance extends CreateRecord
{
    protected static string $resource = AttendanceResource::class;
    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->getResource()::getUrl('index');
    }
}
