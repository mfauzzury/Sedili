<?php

namespace App\Modules\Leave\Services;

use App\Models\Employee;
use App\Modules\Leave\Filament\Portal\MyLeaveResource;
use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Models\LeaveType;

/**
 * The leave balance of one employee for a year, one line per leave type they can take and that has a
 * limit. Shared by the balance rings of the portal and the chat assistant, so that both give the same days.
 */
class LeaveBalanceSummary
{
    public function __construct(private readonly LeaveService $service) {}

    /**
     * @return list<array{type: LeaveType, entitlement: float, approved: float, pending: float, left: float}>
     */
    public function for(Employee $employee, int $year): array
    {
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
            $days[$row->leave_type][$row->status] = (float) $row->getAttribute('total');
        }

        $names = MyLeaveResource::eligibleTypes($employee);
        $types = LeaveType::query()->whereIn('name', $names)->get()->keyBy('name');

        $balances = [];
        foreach ($names as $name) {
            $type = $types->get($name);
            $entitlement = $type ? $this->service->entitlement($employee, $type, $year) : null;
            if ($type === null || $entitlement === null) {
                continue;
            }

            $approved = $days[$name]['Approved'] ?? 0.0;
            $pending = $days[$name]['Pending'] ?? 0.0;
            $balances[] = [
                'type' => $type,
                'entitlement' => $entitlement,
                'approved' => $approved,
                'pending' => $pending,
                'left' => $entitlement - $approved - $pending,
            ];
        }

        return $balances;
    }
}
