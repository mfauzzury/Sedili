<?php

namespace App\Modules\Claims\Tests;

use App\Models\Employee;
use App\Modules\Claims\Models\Claim;
use App\Modules\Claims\Models\ClaimItem;
use App\Modules\Claims\Models\ClaimType;
use App\Modules\Claims\Services\ClaimReceipt;
use Illuminate\Support\Facades\Storage;

/**
 * People and claims for the tests of the module.
 */
trait MakesClaims
{
    private function employee(string $email, array $attributes = [], string $role = 'employee'): Employee
    {
        $employee = new Employee;
        $employee->forceFill([
            'first_name' => ucfirst(strtok($email, '@')),
            'last_name' => 'Test',
            'email' => $email,
            'password' => 'password',
            'employment_type' => 'Permanent',
            'hire_date' => '2018-01-01',
            ...$attributes,
        ])->save();
        $employee->assignRole($role);

        return $employee;
    }

    private function type(string $name): ClaimType
    {
        return ClaimType::where('name', $name)->firstOrFail();
    }

    /**
     * A claim with one item of the given amount, in the given status (the items are added while it
     * is a draft, as in the application). Parking & Toll needs no receipt by default.
     */
    private function claim(Employee $owner, string $status = Claim::DRAFT, float $amount = 50.0, ?string $receipt = null, string $type = 'Parking & Toll'): Claim
    {
        $claim = Claim::create(['employee_id' => $owner->id, 'title' => 'Client visit']);
        ClaimItem::create([
            'claim_id' => $claim->id,
            'claim_type_id' => $this->type($type)->id,
            'date' => '2026-11-10',
            'description' => 'Lunch with the client',
            'amount' => $amount,
            'receipt' => $receipt,
        ]);

        if ($status !== Claim::DRAFT) {
            $claim->update([
                'status' => $status,
                'submitted_at' => now(),
            ]);
        }

        return $claim->fresh();
    }

    /** A receipt stored on the private disk, as the upload field would save it. */
    private function storedReceipt(): string
    {
        $path = ClaimReceipt::DIRECTORY.'/'.bin2hex(random_bytes(8)).'.pdf';
        Storage::disk(ClaimReceipt::DISK)->put($path, 'the receipt');

        return $path;
    }
}
