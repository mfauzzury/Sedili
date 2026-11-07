<?php

namespace App\Modules\Leave;

use App\Modules\Leave\Database\Seeders\HolidaySeeder;
use App\Modules\Leave\Database\Seeders\LeaveTypeSeeder;
use App\Modules\Leave\Filament\Pages\LeaveBalances;
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
        return 'Leave requests, leave types and entitlements, public holidays and leave balances.';
    }

    public function filament(string $panelId): array
    {
        return $panelId === 'admin'
            ? [
                'resources' => [LeaveResource::class, LeaveTypeResource::class, HolidayResource::class],
                'pages' => [LeaveBalances::class],
            ]
            : [];
    }

    public function seeders(): array
    {
        return [LeaveTypeSeeder::class, HolidaySeeder::class];
    }
}
