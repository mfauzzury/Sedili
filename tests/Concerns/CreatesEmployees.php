<?php

namespace Tests\Concerns;

use App\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;

/**
 * Helpers of the tests of the admin screens: roles, employees and the logged in admin.
 */
trait CreatesEmployees
{
    protected function prepareAdminPanel(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    protected function makeEmployee(string $name, string $role = 'employee', array $attributes = []): Employee
    {
        $employee = new Employee;
        $employee->forceFill([
            'first_name' => $name,
            'last_name' => 'Test',
            'email' => strtolower($name).'@example.com',
            'password' => 'password',
            'employment_type' => 'Permanent',
            ...$attributes,
        ])->save();
        $employee->assignRole($role);

        return $employee->fresh();
    }

    protected function loginAsAdmin(): Employee
    {
        $admin = $this->makeEmployee('Admin', 'admin');
        $this->actingAs($admin);

        return $admin;
    }

    /** The required fields of the employee form, with the unique ones taken from the email. */
    protected function employeeFormData(array $overrides = []): array
    {
        static $n = 0;
        $n++;

        return [
            'employee_code' => "E$n",
            'first_name' => 'Nora',
            'last_name' => 'Newhire',
            'email' => "nora$n@example.com",
            'phone' => '012345678'.$n,
            'national_id' => 900000 + $n,
            'next_of_kin_name' => 'Kin',
            'next_of_kin_relationship' => 'Sibling',
            'next_of_kin_phone' => '0111111111',
            'employment_type' => 'Permanent',
            'hire_date' => '2024-01-01',
            ...$overrides,
        ];
    }
}
