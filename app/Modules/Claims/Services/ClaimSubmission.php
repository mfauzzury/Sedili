<?php

namespace App\Modules\Claims\Services;

use App\Models\Employee;
use App\Modules\Claims\Models\Claim;
use App\Modules\Claims\Models\ClaimItem;

/**
 * What the owner of a claim does with it: submit a draft, withdraw it while it waits for a
 * decision, delete a draft. Every rule is checked here, on the current state of the claim.
 */
class ClaimSubmission
{
    public function isOwner(Employee $employee, Claim $claim): bool
    {
        return (int) $claim->employee_id === (int) $employee->id;
    }

    public function canEdit(Employee $employee, Claim $claim): bool
    {
        return $claim->status === Claim::DRAFT && $this->isOwner($employee, $claim);
    }

    public function canWithdraw(Employee $employee, Claim $claim): bool
    {
        return $claim->status === Claim::SUBMITTED && $this->isOwner($employee, $claim);
    }

    /**
     * @return list<string> the reasons why it was not possible (empty when the claim is submitted)
     */
    public function submit(Claim $claim, Employee $employee): array
    {
        $claim->refresh();

        if (! $this->canEdit($employee, $claim)) {
            return ['Only a draft of your own can be submitted.'];
        }
        $items = $claim->items()->with('type')->get();
        if ($items->isEmpty()) {
            return ['Add at least one item before submitting the claim.'];
        }
        // The rule of the type when the claim is submitted (an admin may have changed it since the item was saved)
        $missing = $items->filter(fn (ClaimItem $item) => blank($item->receipt) && $item->type?->requires_receipt);
        if ($missing->isNotEmpty()) {
            return $missing
                ->map(fn (ClaimItem $item) => "Attach the receipt of \"{$item->description}\" ({$item->type?->name}).")
                ->values()
                ->all();
        }

        $claim->update([
            'status' => Claim::SUBMITTED,
            'submitted_at' => now(),
        ]);

        return [];
    }

    /**
     * @return list<string>
     */
    public function withdraw(Claim $claim, Employee $employee): array
    {
        $claim->refresh();

        if (! $this->canWithdraw($employee, $claim)) {
            return ['This claim cannot be withdrawn (it may already have been decided).'];
        }

        $claim->update(['status' => Claim::DRAFT, 'submitted_at' => null]);

        return [];
    }

    /**
     * @return list<string>
     */
    public function delete(Claim $claim, Employee $employee): array
    {
        $claim->refresh();

        if (! $this->canEdit($employee, $claim)) {
            return ['Only a draft of your own can be deleted.'];
        }

        $claim->delete();

        return [];
    }
}
