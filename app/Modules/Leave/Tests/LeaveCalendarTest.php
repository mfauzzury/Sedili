<?php

namespace App\Modules\Leave\Tests;

use App\Models\Department;
use App\Models\Employee;
use App\Modules\Leave\Database\Seeders\LeaveTypeSeeder;
use App\Modules\Leave\Filament\Pages\LeaveCalendar;
use App\Modules\Leave\Filament\Widgets\LeaveCalendarWidget;
use App\Modules\Leave\Models\Holiday;
use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Models\LeaveType;
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

    /**
     * Background colours of the events displayed in November 2026, by title.
     *
     * @return array<string, string>
     */
    private function colors($component): array
    {
        return collect($component->instance()->getEventsJs([
            'startStr' => '2026-10-26T00:00:00',
            'endStr' => '2026-12-07T00:00:00',
            'tzOffset' => 0,
        ]))->pluck('backgroundColor', 'title')->all();
    }

    public function test_the_admin_sees_the_colour_of_each_leave_type(): void
    {
        $this->leave($this->alice, '2026-11-10', '2026-11-11', 'Approved', 'Sick Leave');
        $this->leave($this->bob, '2026-11-16', '2026-11-18', 'Pending', 'Annual Leave');
        LeaveType::where('name', 'Annual Leave')->update(['color' => '#123456']);
        $this->as($this->admin, 'admin');

        $colors = $this->colors(Livewire::test(LeaveCalendarWidget::class));

        $this->assertSame('#dc2626', $colors['Alice Test - Sick Leave']);
        // Approved or pending: the colour is the one of the type
        $this->assertSame('#123456', $colors['Bob Test - Annual Leave (pending)']);
    }

    public function test_an_employee_cannot_tell_the_leave_type_of_a_colleague_from_the_colour(): void
    {
        $this->leave($this->bob, '2026-11-10', '2026-11-11', 'Approved', 'Sick Leave');
        $this->leave($this->alice, '2026-11-16', '2026-11-18', 'Approved', 'Sick Leave');
        $this->as($this->alice, 'portal');

        $colors = $this->colors(Livewire::test(LeaveCalendarWidget::class));

        $this->assertSame(LeaveCalendarWidget::COLLEAGUE_COLOR, $colors['Bob Test']);
        $this->assertNotSame($colors['Bob Test'], $colors['Me - Sick Leave']);
        $this->assertSame('#dc2626', $colors['Me - Sick Leave']);
    }

    public function test_a_leave_type_without_colour_gets_a_fallback_colour(): void
    {
        $type = LeaveType::create(['name' => 'Study Leave']);
        $this->assertSame(LeaveType::FALLBACK_COLORS[($type->id - 1) % count(LeaveType::FALLBACK_COLORS)], $type->displayColor());
    }

    public function test_the_text_colour_is_readable_on_light_and_dark_backgrounds(): void
    {
        $this->assertSame('#ffffff', LeaveType::textColorFor('#2563eb'));
        $this->assertSame('#111827', LeaveType::textColorFor('#fde68a'));
    }

    public function test_the_seeder_does_not_overwrite_the_colour_chosen_by_an_admin(): void
    {
        LeaveType::where('name', 'Sick Leave')->update(['color' => '#000000']);

        $this->seed(LeaveTypeSeeder::class);

        $this->assertSame('#000000', LeaveType::where('name', 'Sick Leave')->value('color'));
        $this->assertSame('#2563eb', LeaveType::where('name', 'Annual Leave')->value('color'));
    }

    public function test_the_legend_lists_the_leave_types_for_an_admin(): void
    {
        $this->as($this->admin, 'admin');

        $labels = collect((new LeaveCalendar())->getLegend())->pluck('label');

        $this->assertTrue($labels->contains('Annual Leave'));
        $this->assertTrue($labels->contains('Sick Leave'));
        $this->assertTrue($labels->contains('Public holiday'));
    }

    public function test_the_legend_of_an_employee_has_the_colleagues_and_only_the_types_they_may_take(): void
    {
        $this->as($this->alice, 'portal'); // a man: no maternity leave

        $labels = collect((new LeaveCalendar())->getLegend())->pluck('label');

        $this->assertTrue($labels->contains('Colleague on leave'));
        $this->assertTrue($labels->contains('My Annual Leave'));
        $this->assertTrue($labels->contains('My Paternity Leave'));
        $this->assertFalse($labels->contains('My Maternity Leave'));
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
