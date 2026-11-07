<?php

namespace App\Modules\Leave;

use App\Modules\Leave\Database\Seeders\HolidaySeeder;
use App\Modules\Leave\Database\Seeders\LeaveTypeSeeder;
use App\Modules\Leave\Filament\Pages\LeaveBalances;
use App\Modules\Leave\Filament\Pages\LeaveCalendar;
use App\Modules\Leave\Filament\Portal\MyLeaveResource;
use App\Modules\Leave\Filament\Widgets\LeaveCalendarWidget;
use App\Modules\Leave\Filament\Widgets\MyLeaveBalances;
use App\Modules\Leave\Filament\Resources\Holidays\HolidayResource;
use App\Modules\Leave\Filament\Resources\LeaveTypes\LeaveTypeResource;
use App\Modules\Leave\Filament\Resources\Leaves\LeaveResource;
use App\Modules\Module;

class LeaveModule extends Module
{
    public function key(): string
    {
        return 'leave';
    }

    public function label(): string
    {
        return 'Leave';
    }

    public function description(): string
    {
        return 'Leave requests, leave types and entitlements, public holidays, leave balances and the leave calendar; employees request their own leave in the portal.';
    }

    public function filament(string $panelId): array
    {
        return match ($panelId) {
            'admin' => [
                'resources' => [LeaveResource::class, LeaveTypeResource::class, HolidayResource::class],
                'pages' => [LeaveBalances::class, LeaveCalendar::class],
                'livewire' => [LeaveCalendarWidget::class],
            ],
            // Employees request their own leave in the portal
            'portal' => [
                'resources' => [MyLeaveResource::class],
                'pages' => [LeaveCalendar::class],
                'widgets' => [MyLeaveBalances::class],
                'livewire' => [LeaveCalendarWidget::class],
            ],
            default => [],
        };
    }

    public function seeders(): array
    {
        return [LeaveTypeSeeder::class, HolidaySeeder::class];
    }
}
