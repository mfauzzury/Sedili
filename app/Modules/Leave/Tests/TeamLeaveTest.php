<?php

namespace App\Modules\Leave\Tests;

use App\Models\Department;
use App\Models\Employee;
use App\Modules\Leave\Database\Seeders\LeaveTypeSeeder;
use App\Modules\Leave\Filament\Portal\Pages\ListTeamLeaves;
use App\Modules\Leave\Filament\Portal\TeamLeaveResource;
use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Notifications\LeaveDecided;
use App\Modules\Leave\Services\LeaveApproval;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class TeamLeaveTest extends TestCase
{
    use RefreshDatabase;

    private Employee $boss;
    private Employee $alice;   // chose boss as supervisor
    private Employee $bob;     // in a department managed by boss
    private Employee $carol;   // reports to nobody

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(LeaveTypeSeeder::class);
        Filament::setCurrentPanel('portal');

        $this->boss = $this->employee('boss@example.com');
        $department = Department::create(['name' => 'Sales', 'manager_id' => $this->boss->id]);
        $this->alice = $this->employee('alice@example.com', ['supervisor_id' => $this->boss->id]);
        $this->bob = $this->employee('bob@example.com', ['department_id' => $department->id]);
        $this->carol = $this->employee('carol@example.com');
    }

    private function employee(string $email, array $attributes = []): Employee
    {
        $employee = new Employee();
        $employee->forceFill([
            'first_name' => ucfirst(strtok($email, '@')),
            'last_name' => 'Test',
            'email' => $email,
            'password' => 'password',
            'employment_type' => 'Permanent',
            'hire_date' => '2018-01-01',
            ...$attributes,
        ])->save();
        $employee->assignRole('employee');
        return $employee;
    }

    private function leave(Employee $employee, string $status = 'Pending'): Leave
    {
        return Leave::create([
            'employee_id' => $employee->id,
            'leave_type' => 'Annual Leave',
            'start_date' => '2026-12-07',
            'end_date' => '2026-12-09',
            'status' => $status,
        ]);
    }

    private function actingAsBoss(): void
    {
        $this->actingAs($this->boss->fresh());
    }

    public function test_a_supervisor_only_sees_the_requests_of_their_team(): void
    {
        $alice = $this->leave($this->alice);
        $bob = $this->leave($this->bob);
        $carol = $this->leave($this->carol);
        $mine = $this->leave($this->boss);
        $this->actingAsBoss();

        Livewire::test(ListTeamLeaves::class)
            ->assertCanSeeTableRecords([$alice, $bob])
            ->assertCanNotSeeTableRecords([$carol, $mine]);
    }

    public function test_an_employee_without_a_team_has_no_team_leave_screen(): void
    {
        $this->leave($this->alice);
        $this->actingAs($this->carol->fresh());

        $this->get(TeamLeaveResource::getUrl('index', panel: 'portal'))->assertForbidden();
        $this->assertFalse(TeamLeaveResource::canViewAny());
        $this->assertNull(TeamLeaveResource::getNavigationBadge());
    }

    public function test_the_menu_shows_the_number_of_pending_requests_of_the_team(): void
    {
        $this->leave($this->alice);
        $this->leave($this->bob);
        $this->leave($this->bob, 'Approved');
        $this->leave($this->carol);
        $this->actingAsBoss();

        $this->assertTrue(TeamLeaveResource::canViewAny());
        $this->assertSame('2', TeamLeaveResource::getNavigationBadge());
    }

    public function test_a_supervisor_approves_a_request_and_the_employee_is_told(): void
    {
        $leave = $this->leave($this->alice);
        $this->actingAsBoss();
        Notification::fake();

        Livewire::test(ListTeamLeaves::class)
            ->callTableAction('approve', $leave)
            ->assertNotified('Leave request approved');

        $leave->refresh();
        $this->assertSame('Approved', $leave->status);
        $this->assertSame($this->boss->id, $leave->actioned_by);
        Notification::assertSentTo($this->alice, LeaveDecided::class, fn($n) => $n->leave->status === 'Approved');
    }

    public function test_a_rejection_needs_a_reason_which_is_sent_to_the_employee(): void
    {
        $leave = $this->leave($this->bob);
        $this->actingAsBoss();
        Notification::fake();

        Livewire::test(ListTeamLeaves::class)
            ->callTableAction('reject', $leave, ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);
        $this->assertSame('Pending', $leave->fresh()->status);

        Livewire::test(ListTeamLeaves::class)
            ->callTableAction('reject', $leave, ['reason' => 'The team is short-staffed.'])
            ->assertNotified('Leave request rejected');

        $leave->refresh();
        $this->assertSame('Rejected', $leave->status);
        $this->assertSame('The team is short-staffed.', $leave->rejection_reason);
        $this->assertSame($this->boss->id, $leave->actioned_by);
        Notification::assertSentTo($this->bob, LeaveDecided::class);
    }

    public function test_the_decision_email_names_who_decided(): void
    {
        $leave = $this->leave($this->alice);
        $this->assertSame([], app(LeaveApproval::class)->decide($leave, $this->boss, 'Approved'));

        $mail = (new LeaveDecided($leave->fresh()))->toMail($this->alice);

        $this->assertStringContainsString('Decided by: Boss Test', implode("\n", $mail->introLines));
    }

    public function test_a_request_that_is_already_decided_cannot_be_decided_again(): void
    {
        $leave = $this->leave($this->alice, 'Approved');
        $this->actingAsBoss();

        Livewire::test(ListTeamLeaves::class)
            ->filterTable('status', 'Approved')
            ->assertCanSeeTableRecords([$leave])
            ->assertTableActionHidden('approve', $leave)
            ->assertTableActionHidden('reject', $leave);

        $errors = app(LeaveApproval::class)->decide($leave, $this->boss, 'Rejected', 'Changed my mind');
        $this->assertNotEmpty($errors);
        $this->assertSame('Approved', $leave->fresh()->status);
    }

    public function test_nobody_can_decide_a_request_outside_their_team_or_their_own(): void
    {
        $service = app(LeaveApproval::class);
        $carol = $this->leave($this->carol);
        $mine = $this->leave($this->boss);

        $this->assertNotEmpty($service->decide($carol, $this->boss, 'Approved'));
        $this->assertNotEmpty($service->decide($mine, $this->boss, 'Approved'));
        // Alice reports to the boss, not the other way round
        $this->assertNotEmpty($service->decide($mine, $this->alice, 'Approved'));

        $this->assertSame('Pending', $carol->fresh()->status);
        $this->assertSame('Pending', $mine->fresh()->status);
    }

    public function test_the_leave_rules_apply_to_the_approval(): void
    {
        $leave = $this->leave($this->alice); // 3 days
        LeaveType::where('name', 'Annual Leave')->update(['tiers' => null, 'days_per_year' => 2]);

        $errors = app(LeaveApproval::class)->decide($leave, $this->boss, 'Approved');

        $this->assertStringContainsString('Not enough Annual Leave balance', implode(' ', $errors));
        $this->assertSame('Pending', $leave->fresh()->status);
    }

    public function test_a_rejection_does_not_depend_on_the_balance_but_needs_a_reason(): void
    {
        $leave = $this->leave($this->alice);
        LeaveType::where('name', 'Annual Leave')->update(['tiers' => null, 'days_per_year' => 2]);
        $service = app(LeaveApproval::class);

        $this->assertNotEmpty($service->decide($leave, $this->boss, 'Rejected', '  '));
        $this->assertSame([], $service->decide($leave, $this->boss, 'Rejected', 'Not this week'));
        $this->assertSame('Rejected', $leave->fresh()->status);
    }

    public function test_an_inactive_supervisor_cannot_decide(): void
    {
        $leave = $this->leave($this->alice);
        $this->boss->update(['is_active' => false]);

        $this->assertNotEmpty(app(LeaveApproval::class)->decide($leave, $this->boss->fresh(), 'Approved'));
        $this->assertSame('Pending', $leave->fresh()->status);
    }

    public function test_an_admin_decision_in_the_admin_panel_is_recorded_too(): void
    {
        $admin = $this->employee('admin@example.com');
        $admin->assignRole('admin');
        $this->actingAs($admin->fresh());
        $leave = $this->leave($this->carol);

        $leave->update(['status' => 'Approved']);

        $this->assertSame($admin->id, $leave->fresh()->actioned_by);
    }
}
