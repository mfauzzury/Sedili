<?php

namespace App\Modules\Leave\Tests;

use App\Models\Department;
use App\Models\Employee;
use App\Modules\Leave\Database\Seeders\LeaveTypeSeeder;
use App\Modules\Leave\Filament\Widgets\LeaveCalendarWidget;
use App\Modules\Leave\Models\Holiday;
use App\Modules\Leave\Models\Leave;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LeaveCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Employee $alice;
    private Employee $bob;
    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(LeaveTypeSeeder::class);
        $it = Department::create(['name' => 'IT', 'code' => 'IT']);
        $hr = Department::create(['name' => 'HR', 'code' => 'HR']);
        $this->alice = $this->employee('alice@example.com', $it->id);
        $this->bob = $this->employee('bob@example.com', $hr->id);
        $this->admin = $this->employee('admin@example.com', $hr->id);
        $this->admin->assignRole('admin');
    }

    private function employee(string $email, int $department): Employee
    {
        $employee = new Employee();
        $employee->forceFill([
            'first_name' => ucfirst(strtok($email, '@')),
            'last_name' => 'Test',
            'email' => $email,
            'password' => 'password',
            'employment_type' => 'Permanent',
            'gender' => 'Male',
            'hire_date' => '2018-01-01',
            'department_id' => $department,
        ])->save();
        return $employee;
    }

    private function leave(Employee $employee, string $start, string $end, string $status, string $type = 'Annual Leave'): Leave
    {
        return Leave::create([
            'employee_id' => $employee->id,
            'leave_type' => $type,
            'start_date' => $start,
            'end_date' => $end,
            'status' => $status,
        ]);
    }

    /**
     * Titles of the events displayed in November 2026, as the calendar fetches them.
     *
     * @return list<string>
     */
    private function titles($component): array
    {
        $events = $component->instance()->getEventsJs([
            'startStr' => '2026-10-26T00:00:00',
            'endStr' => '2026-12-07T00:00:00',
            'tzOffset' => 0,
        ]);
        return collect($events)->pluck('title')->sort()->values()->all();
    }

    private function as(Employee $user, string $panel): void
    {
        Filament::setCurrentPanel($panel);
        $this->actingAs($user->fresh());
    }

    public function test_the_admin_sees_approved_and_pending_leave_with_the_leave_type(): void
    {
        $this->leave($this->alice, '2026-11-10', '2026-11-11', 'Approved', 'Sick Leave');
        $this->leave($this->bob, '2026-11-16', '2026-11-18', 'Pending');
        $this->leave($this->bob, '2026-11-23', '2026-11-24', 'Rejected');
        $this->as($this->admin, 'admin');

        $this->assertSame(
            ['Alice Test - Sick Leave', 'Bob Test - Annual Leave (pending)'],
            $this->titles(Livewire::test(LeaveCalendarWidget::class))
        );
    }

    public function test_an_employee_sees_the_colleagues_approved_leave_without_the_leave_type(): void
    {
        $this->leave($this->bob, '2026-11-10', '2026-11-11', 'Approved', 'Sick Leave');
        $this->leave($this->bob, '2026-11-16', '2026-11-18', 'Pending');
        $this->as($this->alice, 'portal');

        $titles = $this->titles(Livewire::test(LeaveCalendarWidget::class));

        $this->assertSame(['Bob Test'], $titles); // no leave type, and the pending request stays private
    }

    public function test_an_employee_sees_their_own_leave_in_full_pending_included(): void
    {
        $this->leave($this->alice, '2026-11-10', '2026-11-11', 'Approved', 'Sick Leave');
        $this->leave($this->alice, '2026-11-16', '2026-11-18', 'Pending');
        $this->as($this->alice, 'portal');

        $this->assertSame(
            ['Me - Annual Leave (pending)', 'Me - Sick Leave'],
            $this->titles(Livewire::test(LeaveCalendarWidget::class))
        );
    }

    public function test_the_public_holidays_are_displayed(): void
    {
        Holiday::create(['date' => '2026-11-09', 'name' => 'Replacement holiday: Deepavali']);
        $this->as($this->alice, 'portal');

        $this->assertSame(['Replacement holiday: Deepavali'], $this->titles(Livewire::test(LeaveCalendarWidget::class)));
    }

    public function test_a_leave_starting_before_the_displayed_period_is_displayed(): void
    {
        $this->leave($this->bob, '2026-10-28', '2026-11-03', 'Approved');
        $this->as($this->alice, 'portal');

        $this->assertSame(['Bob Test'], $this->titles(Livewire::test(LeaveCalendarWidget::class)));
    }

    public function test_all_day_events_start_and_end_at_midnight_in_the_timezone_of_the_browser(): void
    {
        // 16 to 18 November, seen from Malaysia (UTC+8): the bar must cover exactly 3 days
        $this->leave($this->bob, '2026-11-16', '2026-11-18', 'Approved');
        $this->as($this->alice, 'portal');

        $events = Livewire::test(LeaveCalendarWidget::class)->instance()->getEventsJs([
            'startStr' => '2026-10-26T00:00:00+08:00',
            'endStr' => '2026-12-07T00:00:00+08:00',
            'tzOffset' => 480,
        ]);

        $this->assertCount(1, $events);
        $this->assertSame('2026-11-16T00:00:00+08:00', $events[0]['start']);
        $this->assertSame('2026-11-19T00:00:00+08:00', $events[0]['end']); // exclusive end
    }

    public function test_the_admin_can_filter_by_department_and_leave_type(): void
    {
        $this->leave($this->alice, '2026-11-10', '2026-11-11', 'Approved', 'Sick Leave');
        $this->leave($this->bob, '2026-11-16', '2026-11-18', 'Approved');
        $this->as($this->admin, 'admin');

        $calendar = Livewire::test(LeaveCalendarWidget::class);
        $this->assertCount(2, $this->titles($calendar));

        $calendar->set('departmentId', $this->alice->department_id);
        $this->assertSame(['Alice Test - Sick Leave'], $this->titles($calendar));

        $calendar->set('departmentId', null)->set('leaveType', 'Annual Leave');
        $this->assertSame(['Bob Test - Annual Leave'], $this->titles($calendar));
    }

    public function test_the_pages_are_open_to_the_admin_and_to_employees(): void
    {
        $this->as($this->admin, 'admin');
        $this->get('/leave-calendar')->assertOk();

        $this->flushSession();
        $this->as($this->alice, 'portal');
        $this->get('/portal/leave-calendar')->assertOk();
    }
}
