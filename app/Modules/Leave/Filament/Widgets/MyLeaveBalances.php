<?php

namespace App\Modules\Leave\Filament\Widgets;

use App\Models\Employee;
use App\Modules\Leave\Filament\Portal\MyLeaveResource;
use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Models\LeaveType;
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
        $year = $this->getYear();

        // Days by leave type and status, in one query
        $days = [];
        $rows = Leave::query()
            ->selectRaw('leave_type, status, SUM(days) as total')
            ->where('employee_id', $employee->id)
            ->whereIn('status', LeaveService::CHARGED_STATUSES)
            ->whereYear('start_date', $year)
            ->groupBy('leave_type', 'status')
            ->get();
        foreach ($rows as $row) {
            $days[$row->leave_type][$row->status] = (float) $row->total;
        }

        $balances = [];
        foreach (MyLeaveResource::eligibleTypes($employee) as $name) {
            $type = LeaveType::where('name', $name)->first();
            $entitlement = $type ? $service->entitlement($employee, $type, $year) : null;
            if ($entitlement === null) {
                continue;
            }

            $approved = $days[$name]['Approved'] ?? 0.0;
            $pending = $days[$name]['Pending'] ?? 0.0;
            $left = $entitlement - $approved - $pending;
            $share = fn(float $part): float => $entitlement > 0 ? max(0.0, min(100.0, $part / $entitlement * 100)) : 0.0;

            $balances[] = [
                'name' => $type->name,
                'color' => $type->displayColor(),
                'left' => $service->format($left),
                'total' => $service->format($entitlement),
                'taken' => $service->format($approved),
                'pending' => $service->format($pending),
                'remaining' => $share($left),
                'pendingShare' => $share($pending),
                'over' => $left <= 0,
            ];
        }

        return $balances;
    }
}
