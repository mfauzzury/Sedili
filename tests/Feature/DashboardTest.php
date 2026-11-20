<?php

namespace Tests\Feature;

use App\Filament\Resources\Departments\Widgets\StatsOverview as DepartmentStats;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Widgets\StatsOverview as EmployeeStats;
use App\Models\Department;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Concerns\CreatesEmployees;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use CreatesEmployees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdminPanel();
    }

    /** @return array<string, mixed> label => value */
    private function stats(string $widget): array
    {
        $stats = (new ReflectionMethod($widget, 'getStats'))->invoke(new $widget);

        return collect($stats)->mapWithKeys(fn (Stat $stat) => [(string) $stat->getLabel() => $stat->getValue()])->all();
    }

    public function test_an_admin_sees_the_dashboard_with_its_widgets(): void
    {
        $this->loginAsAdmin();

        // The widgets load after the page, so the page only has to mount them
        $this->get('/')->assertOk()
            ->assertSeeLivewire(EmployeeStats::class)
            ->assertSeeLivewire(DepartmentStats::class);
    }

    public function test_an_employee_cannot_open_the_admin_dashboard(): void
    {
        $this->actingAs($this->makeEmployee('Alice'));

        $this->get('/')->assertForbidden();
    }

    public function test_the_department_widget_counts_departments_and_admins(): void
    {
        $this->loginAsAdmin();
        $this->makeEmployee('SecondAdmin', 'admin');
        $this->makeEmployee('Alice');
        Department::create(['name' => 'Sales']);
        Department::create(['name' => 'Ops']);

        $stats = $this->stats(DepartmentStats::class);

        $this->assertEquals(2, $stats['Total Departments']);
        $this->assertEquals(2, $stats['Total HR Admins']);
    }

    public function test_the_employee_widget_counts_the_people_of_the_employees_list(): void
    {
        $this->loginAsAdmin();
        $this->makeEmployee('Alice');
        $this->makeEmployee('Bob');
        $this->makeEmployee('Gone', attributes: ['is_active' => false]);

        $stats = $this->stats(EmployeeStats::class);

        // The admin is not an employee: the numbers match what the list shows when clicked
        $this->assertEquals(3, $stats['Total Employees']);
        $this->assertEquals(2, $stats['Active Employees']);
        $this->assertEquals(1, $stats['Inactive Employees']);
        Livewire::test(ListEmployees::class)->assertCountTableRecords(3);
    }

    public function test_the_widget_counts_the_active_employees_without_a_supervisor(): void
    {
        $this->loginAsAdmin();
        $boss = $this->makeEmployee('Boss');
        $this->makeEmployee('Alice', attributes: ['supervisor_id' => $boss->id]);

        $stats = $this->stats(EmployeeStats::class);

        // Boss has no supervisor and no department manager; Alice has one
        $this->assertEquals(1, $stats['Without supervisor']);
    }
}
