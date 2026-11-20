<?php

namespace App\Modules\Reports\Tests;

use App\Models\Employee;
use App\Modules\Leave\Database\Seeders\LeaveTypeSeeder;
use App\Modules\Leave\Models\Leave;
use App\Modules\ModuleManager;
use App\Modules\ReportColumn;
use App\Modules\ReportResult;
use App\Modules\Reports\Filament\Pages\Reports;
use App\Modules\Reports\Services\ReportCsv;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsPageTest extends TestCase
{
    use MakesReportData;
    use RefreshDatabase;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(LeaveTypeSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->admin = $this->employee('admin@example.com', [], 'admin');
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_list_shows_the_reports_of_the_enabled_modules(): void
    {
        $this->get(Reports::getUrl())
            ->assertOk()
            ->assertSeeInOrder(['Attendance', 'Attendance summary'])
            ->assertSee('Spending by claim type')
            ->assertSee('Leave balances')
            ->assertSee('Payroll summary')
            ->assertSee('Headcount and turnover')
            ->assertSee('Task workload');
    }

    public function test_every_report_opens_on_an_empty_database(): void
    {
        foreach (app(ModuleManager::class)->reports() as $module => $group) {
            foreach (array_keys($group['reports']) as $key) {
                $this->get(Reports::getUrl(['report' => "{$module}.{$key}"]))->assertOk();
            }
        }
    }

    public function test_every_report_opens_with_a_department_and_a_custom_period(): void
    {
        $sales = $this->department('Sales');

        foreach (app(ModuleManager::class)->reports() as $module => $group) {
            foreach (array_keys($group['reports']) as $key) {
                $this->get(Reports::getUrl([
                    'report' => "{$module}.{$key}",
                    'period' => 'custom',
                    'from' => '2026-03-01',
                    'to' => '2026-01-31', // reversed on purpose: the dates are swapped
                    'department' => $sales->id,
                ]))->assertOk();
            }
        }
    }

    public function test_an_employee_cannot_open_the_reports(): void
    {
        $this->actingAs($this->employee('alice@example.com'));

        $this->get(Reports::getUrl())->assertForbidden();
    }

    public function test_an_unknown_report_is_not_found(): void
    {
        $this->get(Reports::getUrl(['report' => 'leave.nothing']))->assertNotFound();
        $this->get(Reports::getUrl(['report' => 'nothing']))->assertNotFound();
    }

    public function test_the_reports_of_a_disabled_module_are_gone(): void
    {
        app(ModuleManager::class)->setEnabled('claims', false);

        $this->get(Reports::getUrl())->assertOk()->assertDontSee('Spending by claim type');
        $this->get(Reports::getUrl(['report' => 'claims.by_type']))->assertNotFound();
    }

    public function test_the_filters_change_the_figures_and_the_table(): void
    {
        $alice = $this->employee('alice@example.com', ['first_name' => 'Alice']);
        $this->employee('bob@example.com', ['first_name' => 'Bob']);
        // Monday 2 to Friday 6 March: 5 days; Monday 2 to Tuesday 3 November: 2 days
        Leave::create(['employee_id' => $alice->id, 'leave_type' => 'Annual Leave', 'start_date' => '2026-03-02', 'end_date' => '2026-03-06', 'status' => 'Approved']);
        Leave::create(['employee_id' => $alice->id, 'leave_type' => 'Sick Leave', 'start_date' => '2026-11-02', 'end_date' => '2026-11-03', 'status' => 'Approved']);

        $page = Livewire::withQueryParams(['report' => 'leave.taken'])
            ->test(Reports::class)
            ->assertSet('period', 'this_year')
            ->assertSet('from', '2026-01-01')
            ->assertSet('to', '2026-12-31')
            ->assertSee('Alice Test')
            ->assertDontSee('Bob Test')
            ->assertSee('Annual Leave')
            ->assertSee('Sick Leave');
        $this->assertSame('7', $this->stat($page->instance()->getResult()->stats, 'Days taken'));

        // The period preset sets the dates; the Annual Leave column goes away with its days
        $page->set('period', 'this_month')
            ->assertSet('from', '2026-11-01')
            ->assertSet('to', '2026-11-30');
        $this->assertSame('2', $this->stat($page->instance()->getResult()->stats, 'Days taken'));
        $this->assertSame(
            ['Code', 'Employee', 'Department', 'Sick Leave', 'Total days', 'Requests'],
            array_map(fn (ReportColumn $column) => $column->label, $page->instance()->getResult()->columns),
        );

        // Editing a date switches to custom dates
        $page->set('from', '2026-03-01')->assertSet('period', 'custom');
    }

    public function test_the_table_is_searched_and_sorted(): void
    {
        foreach (['alice', 'bob', 'carol'] as $i => $name) {
            $employee = $this->employee("{$name}@example.com");
            Leave::create(['employee_id' => $employee->id, 'leave_type' => 'Annual Leave', 'start_date' => '2026-03-02', 'end_date' => '2026-03-0'.(2 + $i), 'status' => 'Approved']);
        }

        Livewire::withQueryParams(['report' => 'leave.taken'])
            ->test(Reports::class)
            ->sortTable('total', 'desc')
            ->assertSeeInOrder(['Carol Test', 'Bob Test', 'Alice Test'])
            ->searchTable('bob')
            ->assertSee('Bob Test')
            ->assertDontSee('Carol Test');
    }

    public function test_the_report_is_exported_as_csv(): void
    {
        $alice = $this->employee('alice@example.com');
        Leave::create(['employee_id' => $alice->id, 'leave_type' => 'Annual Leave', 'start_date' => '2026-03-02', 'end_date' => '2026-03-06', 'status' => 'Approved']);

        Livewire::withQueryParams(['report' => 'leave.taken'])
            ->test(Reports::class)
            ->callAction('export')
            ->assertFileDownloaded('leave-taken-2026-01-01-to-2026-12-31.csv');

        Livewire::withQueryParams(['report' => 'leave.balances', 'year' => '2025'])
            ->test(Reports::class)
            ->callAction('export')
            ->assertFileDownloaded('leave-balances-2025.csv');
    }

    public function test_the_csv_keeps_raw_numbers_and_neutralises_formulas(): void
    {
        $csv = app(ReportCsv::class)->write(new ReportResult(
            columns: [ReportColumn::text('name', 'Name'), ReportColumn::money('amount', 'Amount', 'MYR'), ReportColumn::number('days', 'Days', 1)],
            rows: [
                ['name' => '=HYPERLINK("http://x")', 'amount' => 1234.5, 'days' => -2],
                ['name' => 'Ali, "Bob"', 'amount' => null, 'days' => 3.25],
            ],
        ));

        $this->assertSame(
            "\u{FEFF}Name,Amount,Days\n\"'=HYPERLINK(\"\"http://x\"\")\",1234.50,-2.0\n\"Ali, \"\"Bob\"\"\",,3.3\n",
            $csv,
        );
    }
}
