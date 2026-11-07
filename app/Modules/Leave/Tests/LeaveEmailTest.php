<?php

namespace App\Modules\Leave\Tests;

use App\Models\Employee;
use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Notifications\LeaveDecided;
use App\Modules\Leave\Notifications\LeaveRequested;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LeaveEmailTest extends TestCase
{
    use RefreshDatabase;

    private Employee $alice;
    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->alice = $this->employee('alice@example.com');
        $this->admin = $this->employee('admin@example.com', 'admin');
    }

    private function employee(string $email, string $role = 'employee', bool $active = true): Employee
    {
        $employee = new Employee();
        $employee->forceFill([
            'first_name' => ucfirst(strtok($email, '@')),
            'last_name' => 'Test',
            'email' => $email,
            'password' => 'password',
            'employment_type' => 'Permanent',
            'is_active' => $active,
        ])->save();
        $employee->assignRole($role);
        return $employee;
    }

    private function leave(string $status = 'Pending', array $extra = []): Leave
    {
        return Leave::create([
            'employee_id' => $this->alice->id,
            'leave_type' => 'Annual Leave',
            'start_date' => '2026-03-02',
            'end_date' => '2026-03-04',
            'status' => $status,
            ...$extra,
        ]);
    }

    public function test_the_active_admins_are_emailed_when_leave_is_requested(): void
    {
        $inactive = $this->employee('old-admin@example.com', 'admin', active: false);
        Notification::fake();

        $this->leave();

        Notification::assertSentTo($this->admin, LeaveRequested::class);
        Notification::assertNotSentTo($inactive, LeaveRequested::class);
        Notification::assertNotSentTo($this->alice, LeaveRequested::class);
        Notification::assertNotSentTo($this->alice, LeaveDecided::class);
    }

    public function test_the_supervisor_gets_the_request_with_a_button_to_the_team_leave(): void
    {
        $boss = $this->employee('boss@example.com');
        $this->alice->update(['supervisor_id' => $boss->id]);
        Notification::fake();

        $this->leave();

        Notification::assertSentTo($this->admin, LeaveRequested::class, fn($n) => $n->asSupervisor === false);
        Notification::assertSentTo($boss, LeaveRequested::class, fn($n) => $n->asSupervisor === true);

        $mail = (new LeaveRequested($this->leave(), asSupervisor: true))->toMail($boss);
        $this->assertStringEndsWith('/portal/team-leave', $mail->actionUrl);
        $this->assertStringContainsString('who reports to you', implode("\n", $mail->introLines));
    }

    public function test_the_manager_of_the_department_is_the_supervisor_when_none_is_chosen(): void
    {
        $manager = $this->employee('manager@example.com');
        $department = \App\Models\Department::create(['name' => 'Sales', 'manager_id' => $manager->id]);
        $this->alice->update(['department_id' => $department->id]);
        Notification::fake();

        $this->leave();

        Notification::assertSentTo($manager, LeaveRequested::class, fn($n) => $n->asSupervisor === true);
    }

    public function test_an_admin_who_supervises_gets_one_email_only_with_the_button(): void
    {
        $this->alice->update(['supervisor_id' => $this->admin->id]);
        Notification::fake();

        $this->leave();

        Notification::assertSentToTimes($this->admin, LeaveRequested::class, 1);
        Notification::assertSentTo($this->admin, LeaveRequested::class, fn($n) => $n->asSupervisor === false);
    }

    public function test_nobody_but_the_admins_is_told_without_a_supervisor(): void
    {
        Notification::fake();

        $this->leave();

        Notification::assertSentTo($this->admin, LeaveRequested::class);
        Notification::assertNotSentTo($this->alice, LeaveRequested::class);
        Notification::assertCount(1);
    }

    public function test_the_employee_is_emailed_when_the_leave_is_approved_or_rejected(): void
    {
        $leave = $this->leave();
        Notification::fake();

        $leave->update(['notes' => 'Edited, still pending']);
        Notification::assertNothingSent();

        $leave->update(['status' => 'Approved']);
        Notification::assertSentTo($this->alice, LeaveDecided::class, fn($n) => $n->leave->status === 'Approved');

        $leave->update(['status' => 'Rejected', 'rejection_reason' => 'Busy period']);
        Notification::assertSentToTimes($this->alice, LeaveDecided::class, 2);
        Notification::assertNotSentTo($this->admin, LeaveDecided::class);
    }

    public function test_a_leave_created_already_approved_tells_the_employee_and_not_the_admins(): void
    {
        Notification::fake();

        $this->leave('Approved');

        Notification::assertSentTo($this->alice, LeaveDecided::class);
        Notification::assertNotSentTo($this->admin, LeaveRequested::class);
    }

    public function test_the_content_of_the_emails(): void
    {
        $leave = $this->leave('Rejected', ['rejection_reason' => 'Busy period', 'notes' => 'Family trip']);
        $leave->refresh();

        $decided = (new LeaveDecided($leave))->toMail($this->alice);
        $this->assertSame('Your leave request was rejected', $decided->subject);
        $text = implode("\n", $decided->introLines);
        $this->assertStringContainsString('2 Mar 2026 to 4 Mar 2026', $text);
        $this->assertStringContainsString('Reason: Busy period', $text);
        $this->assertStringContainsString('/portal/', $decided->actionUrl);

        $requested = (new LeaveRequested($leave))->toMail($this->admin);
        $this->assertSame('Leave request from Alice Test', $requested->subject);
        $this->assertStringContainsString('Notes: Family trip', implode("\n", $requested->introLines));
        $this->assertStringNotContainsString('/portal/', $requested->actionUrl);
    }

    public function test_a_broken_mail_server_does_not_prevent_saving_the_leave(): void
    {
        // Nothing listens on port 1
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
        ]);

        $leave = $this->leave();

        $this->assertDatabaseHas('leave', ['id' => $leave->id, 'status' => 'Pending']);
    }
}
