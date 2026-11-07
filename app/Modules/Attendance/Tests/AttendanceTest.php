<?php

namespace App\Modules\Attendance\Tests;

use App\Models\Employee;
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
}
