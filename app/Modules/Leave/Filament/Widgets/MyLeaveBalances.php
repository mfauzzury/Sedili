<?php

namespace App\Modules\Leave\Filament\Widgets;

use App\Models\Employee;
use App\Modules\Leave\Filament\Portal\MyLeaveResource;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * Remaining leave days of the logged in employee (types with a limit), for the current year.
 */
class MyLeaveBalances extends BaseWidget
{
    protected ?string $heading = 'My leave balance';

    protected function getStats(): array
    {
        $employee = Auth::user();
        if (!$employee instanceof Employee) {
            return [];
        }

        $service = app(LeaveService::class);
        $year = (int) now()->year;
        $stats = [];
        foreach (MyLeaveResource::eligibleTypes($employee) as $name) {
            $type = LeaveType::where('name', $name)->first();
            $entitlement = $service->entitlement($employee, $type, $year);
            if ($entitlement === null) {
                continue;
            }
            $left = $service->balance($employee, $type, $year);
            $stats[] = Stat::make($type->name, $service->format($left) . ' / ' . $service->format($entitlement))
                ->description("days left in {$year}")
                ->color($left <= 0 ? 'danger' : 'primary');
        }
        return $stats;
    }
}
