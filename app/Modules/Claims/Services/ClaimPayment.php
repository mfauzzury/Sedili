<?php

namespace App\Modules\Claims\Services;

use App\Models\Employee;
use App\Modules\Claims\Models\Claim;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * An admin records that an approved claim was paid back to the employee.
 */
class ClaimPayment
{
    public function canPay(Employee $admin, Claim $claim): bool
    {
        return $claim->status === Claim::APPROVED && $admin->hasRole('admin');
    }

    /**
     * @return list<string> the reasons why it was not possible (empty when the payment is saved)
     */
    public function markPaid(Claim $claim, Employee $admin, CarbonInterface|string|null $paidAt, ?string $reference = null): array
    {
        $claim->refresh();

        if (! $this->canPay($admin, $claim)) {
            return ['Only an approved claim can be marked as paid, by an admin.'];
        }

        $claim->update([
            'status' => Claim::PAID,
            'paid_at' => $paidAt ? Carbon::parse($paidAt)->toDateString() : now()->toDateString(),
            'paid_by' => $admin->id,
            'payment_reference' => filled($reference) ? $reference : null,
        ]);

        return [];
    }
}
