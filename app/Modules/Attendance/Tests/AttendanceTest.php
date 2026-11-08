<?php

namespace App\Modules\Attendance\Tests;

use App\Models\Employee;
use App\Modules\Attendance\Filament\Resources\Attendances\Pages\CreateAttendance;
use App\Modules\Attendance\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Shift;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;
    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->employee = new Employee();
        $this->employee->forceFill([
            'first_name' => 'Siti',
            'last_name' => 'Aminah',
            'email' => 'siti@example.com',
            'password' => 'password',
            'employment_type' => 'Permanent',
        ])->save();
        $this->shift = Shift::create(['name' => 'Office hours', 'start_time' => '09:00', 'end_time' => '18:00']);
    }

    private function attendance(string $in, ?string $out, string $date = '2026-03-02'): Attendance
    {
        return Attendance::factory()->create([
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shift->id,
            'date' => $date,
            'clock_in' => $in,
            'clock_out' => $out,
        ]);
    }

    public function test_hours_are_computed_from_clock_in_and_clock_out(): void
    {
        $this->assertEqualsWithDelta(8.0, $this->attendance('09:00', '17:00')->hours, 0.01);
        $this->assertEqualsWithDelta(8.5, $this->attendance('09:00', '17:30', '2026-03-03')->hours, 0.01);
        $this->assertEqualsWithDelta(0.75, $this->attendance('09:00', '09:45', '2026-03-04')->hours, 0.01);
    }

    public function test_hours_are_empty_while_the_employee_is_still_clocked_in(): void
    {
        $this->assertNull($this->attendance('09:00', null)->hours);
    }

    public function test_the_shift_name_is_exposed(): void
    {
        $this->assertSame('Office hours', $this->attendance('09:00', '17:00')->shift_name);
    }

    public function test_an_admin_sees_the_attendance_records(): void
    {
        $admin = new Employee();
        $admin->forceFill([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@example.com',
            'password' => 'password',
            'employment_type' => 'Permanent',
        ])->save();
        $admin->assignRole('admin');
        Filament::setCurrentPanel('admin');
        $this->actingAs($admin);

        $record = $this->attendance('09:00', '17:00');

        Livewire::test(ListAttendances::class)->assertCanSeeTableRecords([$record]);
    }
    private function loginAsAdminOfTheAdminPanel(): void
    {
        $admin = new Employee();
        $admin->forceFill([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@example.com',
            'password' => 'password',
            'employment_type' => 'Permanent',
        ])->save();
        $admin->assignRole('admin');
        Filament::setCurrentPanel('admin');
        $this->actingAs($admin->fresh());
    }

    private function colleague(): Employee
    {
        $colleague = new Employee();
        $colleague->forceFill([
            'first_name' => 'Ali',
            'last_name' => 'Hassan',
            'email' => 'ali@example.com',
            'password' => 'password',
            'employment_type' => 'Permanent',
        ])->save();

        return $colleague;
    }

    public function test_two_employees_can_both_have_attendance_on_the_same_day(): void
    {
        $this->loginAsAdminOfTheAdminPanel();
        $this->attendance('09:00', '18:00', '2026-03-02');

        Livewire::test(CreateAttendance::class)
            ->fillForm([
                'employee_id' => $this->colleague()->id,
                'shift_id' => $this->shift->id,
                'date' => '2026-03-02',
                'clock_in' => '09:05',
                'clock_out' => '18:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Attendance::whereDate('date', '2026-03-02')->count());
    }

    public function test_the_same_employee_cannot_have_two_records_for_the_same_day_and_shift(): void
    {
        $this->loginAsAdminOfTheAdminPanel();
        $this->attendance('09:00', '18:00', '2026-03-02');

        Livewire::test(CreateAttendance::class)
            ->fillForm([
                'employee_id' => $this->employee->id,
                'shift_id' => $this->shift->id,
                'date' => '2026-03-02',
                'clock_in' => '09:30',
            ])
            ->call('create')
            ->assertHasFormErrors(['date' => 'unique']);
    }

    public function test_clocking_out_before_clocking_in_is_refused(): void
    {
        $this->loginAsAdminOfTheAdminPanel();

        Livewire::test(CreateAttendance::class)
            ->fillForm([
                'employee_id' => $this->employee->id,
                'shift_id' => $this->shift->id,
                'date' => '2026-03-03',
                'clock_in' => '18:00',
                'clock_out' => '09:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['clock_out']);
    }

    public function test_the_duration_of_a_shift_counts_the_night(): void
    {
        $night = Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00']);

        $this->assertTrue($night->isOvernight());
        $this->assertFalse($this->shift->isOvernight());
        $this->assertEquals(480, $night->duration);
        $this->assertEquals(540, $this->shift->duration);
    }

    public function test_a_night_shift_may_end_after_midnight(): void
    {
        $this->loginAsAdminOfTheAdminPanel();
        $night = Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00']);

        Livewire::test(CreateAttendance::class)
            ->fillForm([
                'employee_id' => $this->employee->id,
                'shift_id' => $night->id,
                'date' => '2026-03-03',
                'clock_in' => '22:00',
                'clock_out' => '06:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(8.0, Attendance::where('shift_id', $night->id)->firstOrFail()->hours);
    }
}
