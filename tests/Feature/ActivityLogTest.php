<?php

namespace Tests\Feature;

use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Models\Department;
use App\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    private function makeEmployee(string $role, ?string $email = null): Employee
    {
        $employee = new Employee;
        $employee->forceFill([
            'first_name' => 'Test',
            'last_name' => ucfirst($role),
            'email' => $email ?? "$role@example.com",
            'password' => 'password',
            'employment_type' => 'Permanent',
        ])->save();
        $employee->assignRole($role);

        return $employee->fresh();
    }

    public function test_a_change_is_recorded_with_who_made_it_and_only_what_changed(): void
    {
        $admin = $this->makeEmployee('admin');
        $employee = $this->makeEmployee('employee');
        $this->actingAs($admin);

        $employee->update(['first_name' => 'Renamed']);

        $log = Activity::query()->where('log_name', 'Employee')->where('event', 'updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertTrue($log->causer->is($admin));
        $this->assertTrue($log->subject->is($employee));
        $this->assertSame(['first_name' => 'Renamed'], $log->properties['attributes']);
        $this->assertSame(['first_name' => 'Test'], $log->properties['old']);
    }

    public function test_passwords_are_never_written_to_the_log(): void
    {
        $admin = $this->makeEmployee('admin');
        $this->actingAs($admin);

        $admin->update(['password' => 'a-brand-new-password']);
        $this->makeEmployee('employee');

        foreach (Activity::all() as $activity) {
            $this->assertStringNotContainsString('password', json_encode($activity->properties));
            $this->assertStringNotContainsString('remember_token', json_encode($activity->properties));
        }
    }

    public function test_a_save_that_changes_nothing_is_not_recorded(): void
    {
        $employee = $this->makeEmployee('employee');
        $before = Activity::count();

        $employee->update(['first_name' => $employee->first_name]);

        $this->assertSame($before, Activity::count());
    }

    public function test_deleting_a_record_is_recorded(): void
    {
        $this->actingAs($this->makeEmployee('admin'));
        $department = Department::create(['name' => 'Finance', 'code' => 'FIN']);

        $department->delete();

        $this->assertTrue(Activity::where('log_name', 'Department')->where('event', 'created')->exists());
        $this->assertTrue(Activity::where('log_name', 'Department')->where('event', 'deleted')->exists());
    }

    public function test_an_admin_can_read_the_activity_log_but_not_change_it(): void
    {
        $this->actingAs($this->makeEmployee('admin'));
        Department::create(['name' => 'Finance', 'code' => 'FIN']);
        $activity = Activity::where('log_name', 'Department')->firstOrFail();

        Livewire::test(ListActivities::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$activity])
            ->assertTableActionExists('changes');

        $this->assertFalse(\App\Filament\Resources\Activities\ActivityResource::canCreate());
        $this->assertFalse(\App\Filament\Resources\Activities\ActivityResource::canEdit($activity));
        $this->assertFalse(\App\Filament\Resources\Activities\ActivityResource::canDelete($activity));
    }

    public function test_an_employee_cannot_open_the_activity_log(): void
    {
        $this->actingAs($this->makeEmployee('employee'));

        $this->get('/activities')->assertForbidden();
    }
}
