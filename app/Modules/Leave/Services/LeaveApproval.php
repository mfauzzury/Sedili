<?php

namespace App\Modules\Leave\Services;

use App\Models\Employee;
use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Models\LeaveType;

/**
 * Decision of a supervisor on the leave requests of their team (the portal). The admins decide
 * in the admin panel, with the leave form.
 *
 * A supervisor can only decide a pending request of an employee who reports to them (see
 * Employee::reportingTo()), never their own, and with the same rules as the admins (balance,
 * overlaps, ...).
 */
class LeaveApproval
{
    public function __construct(private readonly LeaveService $service)
    {
    }

    public function canDecide(Employee $supervisor, Leave $leave): bool
    {
        return $leave->status === 'Pending'
            && (int) $leave->employee_id !== (int) $supervisor->id
            && Employee::query()->reportingTo($supervisor)->whereKey($leave->employee_id)->exists();
    }

    /**
     * Approve or reject a request.
     *
     * @param 'Approved'|'Rejected' $decision
     * @return list<string> the reasons why it was not possible (empty when the decision is saved)
     */
    public function decide(Leave $leave, Employee $supervisor, string $decision, ?string $reason = null): array
    {
        // Always on the current state of the request, whatever the screen showed
        $leave->refresh();

        if (!in_array($decision, ['Approved', 'Rejected'], true) || !$this->canDecide($supervisor, $leave)) {
            return ['You cannot decide this leave request (it may already have been decided).'];
        }

        if ($decision === 'Rejected') {
            if (blank($reason)) {
                return ['Give the reason of the rejection.'];
            }
        } else {
            $errors = $this->service->validate(
                (int) $leave->employee_id,
                $leave->leave_type,
                $leave->start_date,
                $leave->end_date,
                'Approved',
                $leave->id
            );
            if (LeaveType::requiresEvidence($leave->leave_type) && blank($leave->attachment)) {
                $errors[] = 'The evidence required for this leave type (for example a medical certificate) is missing.';
            }
            if ($errors !== []) {
                return $errors;
            }
        }

        $leave->update([
            'status' => $decision,
            'actioned_by' => $supervisor->id,
            'rejection_reason' => $decision === 'Rejected' ? $reason : null,
        ]);

        return [];
    }
}
