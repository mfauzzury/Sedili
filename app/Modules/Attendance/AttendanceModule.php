<?php

namespace App\Modules\Attendance;

use App\Modules\Attendance\Filament\Resources\Attendances\AttendanceResource;
use App\Modules\Module;

class AttendanceModule extends Module
{
    public function key(): string
    {
        return 'attendance';
    }

    public function label(): string
    {
        return 'Attendance';
    }

    public function description(): string
    {
        return 'Daily attendance records of the employees and work shifts.';
    }

    public function filament(string $panelId): array
    {
        return $panelId === 'admin' ? ['resources' => [AttendanceResource::class]] : [];
    }
}
