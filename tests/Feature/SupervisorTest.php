<?php

namespace Tests\Feature;

use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Widgets\StatsOverview;
use App\Models\Department;
use App\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupervisorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    private function employee(string $name, array $attributes = [], string $role = 'employee'): Employee
    {
        $employee = new Employee();
        $employee->forceFill([
            'first_name' => $name,
            'last_name' => 'Test',
            'email' => strtolower($name) . '@example.com',
            'password' => 'password',
            'employment_type' => 'Permanent',
            ...$attributes,
        ])->save();
        $employee->assignRole($role);
        return $employee;
    }

    /** The required fields of the employee form that these tests do not care about. */
    private function requiredFields(): array
    {
        return [
            'employee_code' => 'E1', 'phone' => '0123456789', 'national_id' => '123456', 'hire_date' => '2024-01-01',
            'next_of_kin_name' => 'Kin', 'next_of_kin_relationship' => 'Sibling', 'next_of_kin_phone' => '0111111111',
        ];
    }

    private function loginAsAdmin(): Employee
    {
        $admin = $this->employee('Admin', role: 'admin');
        $this->actingAs($admin->fresh());
        return $admin;
    }

    public function test_the_chosen_supervisor_is_used_before_the_manager_of_the_department(): void
    {
        $manager = $this->employee('Manager');
        $boss = $this->employee('Boss');
        $department = Department::create(['name' => 'Sales', 'manager_id' => $manager->id]);
        $alice = $this->employee('Alice', ['department_id' => $department->id]);

        $this->assertTrue($alice->fresh()->effectiveSupervisor()->is($manager));

        $alice->update(['supervisor_id' => $boss->id]);
        $this->assertTrue($alice->fresh()->effectiveSupervisor()->is($boss));
    }

    public function test_an_inactive_or_missing_supervisor_counts_as_none(): void
    {
        $boss = $this->employee('Boss');
        $alice = $this->employee('Alice', ['supervisor_id' => $boss->id]);
        $this->assertTrue($alice->fresh()->effectiveSupervisor()->is($boss));

        $boss->update(['is_active' => false]);
        $this->assertNull($alice->fresh()->effectiveSupervisor());
        $this->assertTrue(Employee::withoutSupervisor()->get()->contains('id', $alice->id));

        // A deleted supervisor too
        $boss->update(['is_active' => true]);
        $boss->delete();
        $this->assertNull($alice->fresh()->effectiveSupervisor());
    }

    public function test_the_manager_of_a_department_is_not_their_own_supervisor(): void
    {
        $manager = $this->employee('Manager');
        $department = Department::create(['name' => 'Sales', 'manager_id' => $manager->id]);
        $manager->update(['department_id' => $department->id]);

        $this->assertNull($manager->fresh()->effectiveSupervisor());
        $this->assertTrue(Employee::withoutSupervisor()->get()->contains('id', $manager->id));
    }

    public function test_the_scope_finds_exactly_the_employees_without_a_supervisor(): void
    {
        $manager = $this->employee('Manager');
        $boss = $this->employee('Boss');
        $department = Department::create(['name' => 'Sales', 'manager_id' => $manager->id]);
        $emptyDepartment = Department::create(['name' => 'Ops']);

        $withBoss = $this->employee('WithBoss', ['supervisor_id' => $boss->id]);
        $viaDepartment = $this->employee('ViaDepartment', ['department_id' => $department->id]);
        $noManager = $this->employee('NoManager', ['department_id' => $emptyDepartment->id]);
        $nothing = $this->employee('Nothing');

        $ids = Employee::withoutSupervisor()->pluck('id')->all();

        $this->assertContains($noManager->id, $ids);
        $this->assertContains($nothing->id, $ids);
        $this->assertNotContains($withBoss->id, $ids);
        $this->assertNotContains($viaDepartment->id, $ids);

        // The PHP rule and the SQL rule agree for everybody
        foreach (Employee::with(['supervisor', 'department.manager'])->get() as $employee) {
            $this->assertSame(
                $employee->effectiveSupervisor() === null,
                in_array($employee->id, $ids, true),
                "Rules disagree for {$employee->first_name}"
            );
        }
    }

    public function test_the_team_of_a_supervisor_follows_the_same_rule_as_the_supervisor_of_an_employee(): void
    {
        $manager = $this->employee('Manager');
        $boss = $this->employee('Boss');
        $left = $this->employee('Left', ['is_active' => false]);
        $sales = Department::create(['name' => 'Sales', 'manager_id' => $manager->id]);
        $ops = Department::create(['name' => 'Ops', 'manager_id' => $left->id]);

        $this->employee('InSales', ['department_id' => $sales->id]);
        $this->employee('InSalesChoseBoss', ['department_id' => $sales->id, 'supervisor_id' => $boss->id]);
        $this->employee('InSalesChoseLeft', ['department_id' => $sales->id, 'supervisor_id' => $left->id]);
        $this->employee('InOps', ['department_id' => $ops->id]);
        $this->employee('ChoseBoss', ['supervisor_id' => $boss->id]);
        $this->employee('ChoseManager', ['supervisor_id' => $manager->id]);
        $this->employee('Nobody');
        $manager->update(['department_id' => $sales->id]); // manages a department he belongs to

        $everyone = Employee::with(['supervisor', 'department.manager'])->get();
        foreach ($everyone as $possibleSupervisor) {
            $team = Employee::reportingTo($possibleSupervisor)->pluck('id')->all();
            foreach ($everyone as $employee) {
                $this->assertSame(
                    $employee->effectiveSupervisor()?->is($possibleSupervisor) ?? false,
                    in_array($employee->id, $team, true),
                    "{$employee->first_name} / team of {$possibleSupervisor->first_name}"
                );
            }
        }

        // Sanity checks of the rule itself
        $this->assertEqualsCanonicalizing(
            ['InSales', 'ChoseManager', 'InSalesChoseLeft'],
            Employee::reportingTo($manager)->pluck('first_name')->all()
        );
        $this->assertEqualsCanonicalizing(['InSalesChoseBoss', 'ChoseBoss'], Employee::reportingTo($boss)->pluck('first_name')->all());
        $this->assertSame([], Employee::reportingTo($left)->pluck('id')->all(), 'An inactive employee has no team');
    }

    public function test_the_list_shows_the_supervisor_and_flags_who_has_none(): void
    {
        $this->loginAsAdmin();
        $boss = $this->employee('Boss');
        $withBoss = $this->employee('WithBoss', ['supervisor_id' => $boss->id]);
        $lonely = $this->employee('Lonely');

        $table = Livewire::test(ListEmployees::class);
        $table->assertCanSeeTableRecords([$withBoss, $lonely]);
        $table->assertTableColumnStateSet('supervisor', 'Boss Test', $withBoss)
            ->assertTableColumnStateSet('supervisor', 'No supervisor', $lonely);
        $table->filterTable('no_supervisor');
        $table->assertCanSeeTableRecords([$lonely, $boss])
            ->assertCanNotSeeTableRecords([$withBoss]);
    }

    public function test_the_dashboard_counts_the_active_employees_without_a_supervisor(): void
    {
        $this->loginAsAdmin(); // an admin is not in the Employees list: not counted
        $boss = $this->employee('Boss');
        $this->employee('WithBoss', ['supervisor_id' => $boss->id]);
        $this->employee('Gone', ['is_active' => false]);

        $stats = Livewire::test(StatsOverview::class)->instance();
        $stat = collect((fn() => $this->getStats())->call($stats))
            ->first(fn($stat) => $stat->getLabel() === 'Without supervisor');

        // Only Boss: not the admin, not WithBoss, not the inactive one
        $this->assertSame(1, $stat->getValue());
    }

    public function test_an_admin_sets_the_supervisor_of_an_employee(): void
    {
        $this->loginAsAdmin();
        $boss = $this->employee('Boss');
        $alice = $this->employee('Alice');

        Livewire::test(EditEmployee::class, ['record' => $alice->getRouteKey()])
            ->fillForm($this->requiredFields() + ['supervisor_id' => $boss->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($boss->id, $alice->fresh()->supervisor_id);
    }

    public function test_the_supervisor_must_be_active_and_not_the_employee_themselves(): void
    {
        $this->loginAsAdmin();
        $alice = $this->employee('Alice');
        $gone = $this->employee('Gone', ['is_active' => false]);

        $page = Livewire::test(EditEmployee::class, ['record' => $alice->getRouteKey()]);

        $page->fillForm(['supervisor_id' => $alice->id])->call('save')->assertHasFormErrors(['supervisor_id']);
        $page->fillForm(['supervisor_id' => $gone->id])->call('save')->assertHasFormErrors(['supervisor_id']);
    }

    public function test_two_employees_cannot_report_to_each_other(): void
    {
        $this->loginAsAdmin();
        $boss = $this->employee('Boss');
        $alice = $this->employee('Alice', ['supervisor_id' => $boss->id]);

        // Boss reports to Alice who reports to Boss
        Livewire::test(EditEmployee::class, ['record' => $boss->getRouteKey()])
            ->fillForm(['supervisor_id' => $alice->id])
            ->call('save')
            ->assertHasFormErrors(['supervisor_id']);

        $this->assertNull($boss->fresh()->supervisor_id);
    }
}
