<?php

namespace App\Observers;

use App\Models\Employee;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EmployeeObserver
{
    /**
     * Handle the Employee "created" event.
     */
    public function creating(Employee $employee): void
    {
        // No password given: set a random one. The employee chooses a password with
        // "Forgot password" on the login page (never derive it from public data like the email).
        if (empty($employee->password)) {
            $employee->password = Hash::make(Str::password(32));
        }
    }
    public function created(Employee $employee): void
    {
        //

    }

    /**
     * Handle the Employee "updated" event.
     */
    public function updated(Employee $employee): void
    {
        //
    }

    /**
     * Handle the Employee "deleted" event.
     */
    public function deleted(Employee $employee): void
    {
        //
    }

    /**
     * Handle the Employee "restored" event.
     */
    public function restored(Employee $employee): void
    {
        //
    }

    /**
     * Handle the Employee "force deleted" event.
     */
    public function forceDeleted(Employee $employee): void
    {
        //
    }
}
