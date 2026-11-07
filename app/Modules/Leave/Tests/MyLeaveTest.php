<?php

namespace App\Modules\Leave\Tests;

use App\Models\Employee;
use App\Modules\Leave\Filament\Portal\Pages\ListMyLeaves;
use App\Modules\Leave\Filament\Widgets\MyLeaveBalances;
use App\Modules\Leave\Models\Leave;
use Database\Seeders\RolePermissionSeeder;
use App\Modules\Leave\Database\Seeders\LeaveTypeSeeder;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyLeaveTest extends TestCase
{
    use RefreshDatabase;

    private Employee $alice;
    private Employee $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(LeaveTypeSeeder::class);
        $this->alice = $this->employee('alice@example.com', 'Female', '2025-06-01'); // 8 annual days
        $this->bob = $this->employee('bob@example.com', 'Male', '2018-01-01');
        Filament::setCurrentPanel('portal');
        // A logged in user is always loaded from the database, with all its columns
        $this->actingAs($this->alice->fresh());
    }

    private function employee(string $email, string $gender, string $hired): Employee
    {
        $employee = new Employee();
        $employee->forceFill([
            'first_name' => ucfirst(strtok($email, '@')),
            'last_name' => 'Test',
            'email' => $email,
            'password' => 'password',
            'employment_type' => 'Permanent',
            'gender' => $gender,
            'hire_date' => $hired,
        ])->save();
        return $employee;
    }

    private function leave(Employee $employee, string $start, string $end, string $status = 'Pending', string $type = 'Annual Leave'): Leave
    {
        return Leave::create([
            'employee_id' => $employee->id,
            'leave_type' => $type,
            'start_date' => $start,
            'end_date' => $end,
            'status' => $status,
        ]);
    }

    private function request(array $data)
    {
        return Livewire::test(ListMyLeaves::class)->callAction(CreateAction::class, array_merge([
            'leave_type' => 'Annual Leave',
            'start_date' => '2026-03-02',
            'end_date' => '2026-03-03',
        ], $data));
    }

    public function test_an_employee_only_sees_their_own_requests(): void
    {
        $mine = $this->leave($this->alice, '2026-03-02', '2026-03-03');
        $theirs = $this->leave($this->bob, '2026-04-06', '2026-04-07');

        Livewire::test(ListMyLeaves::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_a_request_is_created_as_pending_for_the_logged_in_employee(): void
    {
        $this->request(['notes' => 'Family trip'])->assertHasNoActionErrors();

        $leave = Leave::firstOrFail();
        $this->assertSame($this->alice->id, $leave->employee_id);
        $this->assertSame('Pending', $leave->status);
        $this->assertSame(2.0, $leave->days);
        $this->assertSame('Family trip', $leave->notes);
    }

    public function test_a_request_cannot_be_made_for_someone_else_or_approved_by_the_form(): void
    {
        $this->request(['employee_id' => $this->bob->id, 'status' => 'Approved'])->assertHasNoActionErrors();

        $leave = Leave::firstOrFail();
        $this->assertSame($this->alice->id, $leave->employee_id);
        $this->assertSame('Pending', $leave->status);
    }

    public function test_a_request_above_the_balance_is_refused(): void
    {
        // 8 annual days: March 2 to March 13 is 10 working days
        $this->request(['end_date' => '2026-03-13'])->assertHasActionErrors(['end_date']);
        $this->assertSame(0, Leave::count());
    }

    public function test_an_overlapping_request_is_refused(): void
    {
        $this->leave($this->alice, '2026-03-02', '2026-03-04');

        $this->request(['start_date' => '2026-03-04', 'end_date' => '2026-03-05'])->assertHasActionErrors(['end_date']);
        $this->assertSame(1, Leave::count());
    }

    public function test_leave_types_for_the_other_gender_are_refused(): void
    {
        // Alice is female: paternity leave is for male employees
        $this->request(['leave_type' => 'Paternity Leave'])->assertHasActionErrors();
        $this->assertSame(0, Leave::count());
    }

    public function test_the_admins_are_notified_of_a_new_request(): void
    {
        $admin = $this->employee('admin@example.com', 'Male', '2020-01-01');
        $admin->assignRole('admin');

        $this->request([])->assertHasNoActionErrors();

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(0, $this->bob->notifications()->count());
    }

    public function test_a_pending_request_can_be_cancelled(): void
    {
        $leave = $this->leave($this->alice, '2026-03-02', '2026-03-03');

        Livewire::test(ListMyLeaves::class)->callTableAction('cancel', $leave);

        $this->assertModelMissing($leave);
    }

    public function test_an_approved_request_cannot_be_cancelled(): void
    {
        $leave = $this->leave($this->alice, '2026-03-02', '2026-03-03', 'Approved');

        Livewire::test(ListMyLeaves::class)->assertTableActionHidden('cancel', $leave);
        $this->assertModelExists($leave);
    }

    public function test_the_request_of_another_employee_cannot_be_cancelled(): void
    {
        $theirs = $this->leave($this->bob, '2026-04-06', '2026-04-07');

        try {
            Livewire::test(ListMyLeaves::class)->callTableAction('cancel', $theirs);
        } catch (\Throwable) {
            // The record is not part of the query of the employee: Filament refuses the action
        }

        $this->assertModelExists($theirs);
    }

    public function test_the_balance_widget_shows_the_remaining_days(): void
    {
        $this->leave($this->alice, '2026-03-02', '2026-03-04'); // 3 of 8 annual days

        Livewire::test(MyLeaveBalances::class)
            ->assertSee('Annual Leave')
            ->assertSee('5 / 8');
    }

    public function test_the_page_is_open_to_an_employee_and_closed_to_anonymous_visitors(): void
    {
        $this->get('/portal/my-leave')->assertOk();

        auth()->logout();
        $this->get('/portal/my-leave')->assertRedirect();
    }
}
