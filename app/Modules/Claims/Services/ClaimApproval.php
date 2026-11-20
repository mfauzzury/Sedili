<?php

namespace App\Modules\Claims\Services;

use App\Models\Employee;
use App\Modules\Claims\Models\Claim;

/**
 * Decision on a submitted claim: by the supervisor of the employee (portal, Team Claims) or by an
 * admin (admin panel). Nobody decides their own claim.
 */
class ClaimApproval
{
    public function canDecide(Employee $decider, Claim $claim): bool
    {
        if ($claim->status !== Claim::SUBMITTED || (int) $claim->employee_id === (int) $decider->id) {
            return false;
        }

        return $decider->hasRole('admin')
            || Employee::query()->reportingTo($decider)->whereKey($claim->employee_id)->exists();
    }

    /**
     * @param  string  $decision  Claim::APPROVED or Claim::REJECTED (anything else is refused)
     * @return list<string> the reasons why it was not possible (empty when the decision is saved)
     */
    public function decide(Claim $claim, Employee $decider, string $decision, ?string $reason = null): array
    {
        // Always on the current state of the claim, whatever the screen showed
        $claim->refresh();

        if (! in_array($decision, [Claim::APPROVED, Claim::REJECTED], true) || ! $this->canDecide($decider, $claim)) {
            return ['You cannot decide this claim (it may already have been decided).'];
        }
        if ($decision === Claim::REJECTED && blank($reason)) {
            return ['Give the reason of the rejection.'];
        }

        $claim->update([
            'status' => $decision,
            'actioned_by' => $decider->id,
            'actioned_at' => now(),
            'rejection_reason' => $decision === Claim::REJECTED ? $reason : null,
        ]);

        return [];
    }
}
