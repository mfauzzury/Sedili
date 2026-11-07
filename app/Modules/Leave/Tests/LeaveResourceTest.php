<?php

namespace App\Modules\Leave\Tests;

use App\Modules\Leave\Filament\Resources\Leaves\Pages\ListLeaves;
use App\Models\Employee;
use App\Modules\Leave\Models\Leave;
use Database\Seeders\RolePermissionSeeder;
use App\Modules\Leave\Database\Seeders\LeaveTypeSeeder;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LeaveResourceTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(LeaveTypeSeeder::class);
        $this->admin = new Employee();
        $this->admin->forceFill([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@example.com',
            'password' => 'password',
            'employment_type' => 'Permanent',
            'gender' => 'Male',
            'hire_date' => '2020-01-01',
        ])->save();
        $this->admin->assignRole('admin');
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);
    }

    private function createLeave(array $data)
    {
        return Livewire::test(ListLeaves::class)->callAction(CreateAction::class, array_merge([
            'employee_id' => $this->admin->id,
            'leave_type' => 'Annual Leave',
            'start_date' => '2026-03-02',
            'end_date' => '2026-03-03',
            'status' => 'Pending',
        ], $data));
    }

    public function test_a_valid_leave_can_be_created(): void
    {
        $this->createLeave([])->assertHasNoActionErrors();
        $this->assertDatabaseHas('leave', ['employee_id' => $this->admin->id, 'days' => 2.0]);
    }

    public function test_an_overlapping_leave_is_refused(): void
    {
        $this->createLeave([])->assertHasNoActionErrors();
        $this->createLeave(['start_date' => '2026-03-03', 'end_date' => '2026-03-04'])
            ->assertHasActionErrors(['end_date']);
        $this->assertSame(1, Leave::count());
    }

    public function test_a_leave_above_the_balance_is_refused(): void
    {
        // 6 years of service: 16 days; March 2 to March 27 is 20 working days
        $this->createLeave(['end_date' => '2026-03-27'])->assertHasActionErrors(['end_date']);
        $this->assertSame(0, Leave::count());
    }

    public function test_an_end_date_before_the_start_date_is_refused(): void
    {
        $this->createLeave(['start_date' => '2026-03-05', 'end_date' => '2026-03-03'])
            ->assertHasActionErrors(['end_date']);
    }
}
