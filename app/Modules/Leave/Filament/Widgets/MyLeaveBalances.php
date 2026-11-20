<?php

namespace App\Modules\Leave\Filament\Widgets;

use App\Models\Employee;
use App\Modules\Leave\Services\LeaveBalanceSummary;
use App\Modules\Leave\Services\LeaveService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * Leave balance of the logged in employee for the current year, one small ring per leave type that
 * has a limit: the days left in the colour of the type, the pending days in amber, the days already
 * taken in grey.
 */
class MyLeaveBalances extends Widget
{
    protected string $view = 'leave::my-leave-balances';

    protected int|string|array $columnSpan = 'full';

    public function getYear(): int
    {
        return (int) now()->year;
    }

    /**
     * @return list<array{name: string, color: string, left: string, total: string, taken: string, pending: string, remaining: float, pendingShare: float, over: bool}>
     *   "remaining" and "pendingShare" are percentages (0 to 100) of the entitlement, to draw the ring
     */
    public function getBalances(): array
    {
        $employee = Auth::user();
        if (!$employee instanceof Employee) {
            return [];
        }

        $service = app(LeaveService::class);

        $balances = [];
        foreach (app(LeaveBalanceSummary::class)->for($employee, $this->getYear()) as $row) {
            $entitlement = $row['entitlement'];
            $share = fn(float $part): float => $entitlement > 0 ? max(0.0, min(100.0, $part / $entitlement * 100)) : 0.0;

            $balances[] = [
                'name' => $row['type']->name,
                'color' => $row['type']->displayColor(),
                'left' => $service->format($row['left']),
                'total' => $service->format($entitlement),
                'taken' => $service->format($row['approved']),
                'pending' => $service->format($row['pending']),
                'remaining' => $share($row['left']),
                'pendingShare' => $share($row['pending']),
                'over' => $row['left'] <= 0,
            ];
        }

        return $balances;
    }
}
