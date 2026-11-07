<?php

namespace Tests\Feature;

use App\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_employee_created_without_password_does_not_get_the_email_as_password(): void
    {
        $employee = new Employee();
        $employee->forceFill([
            'first_name' => 'Siti',
            'last_name' => 'Aminah',
            'email' => 'siti@example.com',
            'employment_type' => 'Permanent',
        ])->save();

        $this->assertNotEmpty($employee->password);
        $this->assertFalse(Hash::check('siti@example.com', $employee->password));
    }

    public function test_the_create_admin_command_creates_an_administrator(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->artisan('app:create-admin', ['email' => 'boss@example.com'])
            ->expectsQuestion('Password (at least 8 characters)', 'a-strong-password')
            ->assertExitCode(0);

        $admin = Employee::where('email', 'boss@example.com')->firstOrFail();
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue(Hash::check('a-strong-password', $admin->password));
    }

    public function test_the_create_admin_command_refuses_a_short_password(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->artisan('app:create-admin', ['email' => 'boss@example.com'])
            ->expectsQuestion('Password (at least 8 characters)', 'short')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('employees', ['email' => 'boss@example.com']);
    }

    private function employee(string $email, bool $active = true): Employee
    {
        $employee = new Employee();
        $employee->forceFill([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $email,
            'password' => 'password',
            'employment_type' => 'Permanent',
            'is_active' => $active,
        ])->save();
        return $employee;
    }

    public function test_an_active_employee_can_open_the_portal_but_not_the_admin_panel(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $employee = $this->employee('staff@example.com');

        $this->actingAs($employee)->get('/portal')->assertOk();
        $this->actingAs($employee)->get('/')->assertForbidden();
    }

    public function test_an_admin_can_open_the_admin_panel(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = $this->employee('admin@example.com');
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('/')->assertOk();
    }

    public function test_an_inactive_employee_cannot_open_any_panel(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $employee = $this->employee('left@example.com', active: false);
        $employee->assignRole('admin');

        $this->actingAs($employee)->get('/portal')->assertForbidden();
        $this->actingAs($employee)->get('/')->assertForbidden();
    }
}
