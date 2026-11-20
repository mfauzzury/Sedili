<?php

namespace App\Modules\Reports\Tests;

use App\Models\Employee;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Reports\AttendanceSummaryReport;
use App\Modules\Claims\Database\Seeders\ClaimTypeSeeder;
use App\Modules\Claims\Models\Claim;
use App\Modules\Claims\Models\ClaimItem;
use App\Modules\Claims\Models\ClaimType;
use App\Modules\Claims\Reports\ClaimsByEmployeeReport;
use App\Modules\Claims\Reports\ClaimsByTypeReport;
use App\Modules\Leave\Database\Seeders\LeaveTypeSeeder;
use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Reports\LeaveBalanceReport;
use App\Modules\Leave\Reports\LeaveTakenReport;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Reports\PayrollSummaryReport;
use App\Modules\Reports\Reports\HeadcountReport;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Reports\TaskWorkloadReport;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The figures of each report.
 */
class ReportFiguresTest extends TestCase
{
    use MakesReportData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 10:00:00');
        Notification::fake();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_headcount_joiners_leavers_and_turnover(): void
    {
        $sales = $this->department('Sales');
        $ops = $this->department('Operations');
        $this->employee('ann@example.com', ['department_id' => $sales->id]);
        $this->employee('ben@example.com', ['department_id' => $sales->id, 'employment_type' => 'Contract', 'hire_date' => '2026-03-01']);
        $this->employee('cat@example.com', ['department_id' => $sales->id, 'hire_date' => '2019-05-01', 'termination_date' => '2026-06-30', 'is_active' => false]);
        // Inactive without a termination date: not counted at all
        $this->employee('dan@example.com', ['department_id' => $ops->id, 'employment_type' => 'Casual', 'is_active' => false]);

        $result = (new HeadcountReport)->run($this->filters('2026-01-01', '2026-12-31', $sales));

        $this->assertSame([[
            'department' => 'Sales', 'headcount' => 2, 'permanent' => 1, 'contract' => 1, 'casual' => 0, 'joiners' => 1, 'leavers' => 1,
        ]], $result->rows);
        // 2 at the start (Ann, Cat), 2 at the end (Ann, Ben): 1 leaver / 2
        $this->assertSame('50%', $this->stat($result->stats, 'Turnover'));

        // Before Ben was hired and while Cat was still there
        $result = (new HeadcountReport)->run($this->filters('2025-01-01', '2025-12-31', $sales));
        $this->assertSame(2, $result->rows[0]['headcount']);
        $this->assertSame(0, $result->rows[0]['joiners']);

        $this->assertSame([], (new HeadcountReport)->run($this->filters('2026-01-01', '2026-12-31', $ops))->rows);
    }

    public function test_leave_taken_counts_the_days_inside_the_period(): void
    {
        $this->seed(LeaveTypeSeeder::class);
        $sales = $this->department('Sales');
        $alice = $this->employee('alice@example.com', ['department_id' => $sales->id]);
        $bob = $this->employee('bob@example.com');
        // Friday 30 January to Tuesday 3 February: 1 day in January, 2 in February
        Leave::create(['employee_id' => $alice->id, 'leave_type' => 'Annual Leave', 'start_date' => '2026-01-30', 'end_date' => '2026-02-03', 'status' => 'Approved']);
        Leave::create(['employee_id' => $alice->id, 'leave_type' => 'Sick Leave', 'start_date' => '2026-02-10', 'end_date' => '2026-02-10', 'status' => 'Pending']);
        Leave::create(['employee_id' => $alice->id, 'leave_type' => 'Sick Leave', 'start_date' => '2026-02-11', 'end_date' => '2026-02-11', 'status' => 'Rejected']);
        Leave::create(['employee_id' => $bob->id, 'leave_type' => 'Annual Leave', 'start_date' => '2026-02-16', 'end_date' => '2026-02-16', 'status' => 'Approved']);

        $report = app(LeaveTakenReport::class);
        $result = $report->run($this->filters('2026-02-01', '2026-02-28'));

        $this->assertCount(2, $result->rows);
        $alices = collect($result->rows)->firstWhere('employee', 'Alice Test');
        $this->assertSame(2.0, $alices['total']);
        $this->assertSame('Sales', $alices['department']);
        $this->assertSame('3', $this->stat($result->stats, 'Days taken'));
        $this->assertSame('1 days', $this->stat($result->stats, 'Pending'));
        $this->assertSame(['Annual Leave' => 3.0], array_combine($result->chart->labels, $result->chart->values));

        $result = $report->run($this->filters('2026-01-01', '2026-01-31'));
        $this->assertSame(1.0, $result->rows[0]['total']);

        // Only Alice in Sales
        $result = $report->run($this->filters('2026-02-01', '2026-02-28', $sales));
        $this->assertSame(['Alice Test'], array_column($result->rows, 'employee'));
    }

    public function test_leave_balances_of_a_year(): void
    {
        $this->seed(LeaveTypeSeeder::class);
        $alice = $this->employee('alice@example.com');
        $this->employee('gone@example.com', ['is_active' => false, 'termination_date' => '2026-01-31']);
        Leave::create(['employee_id' => $alice->id, 'leave_type' => 'Annual Leave', 'start_date' => '2026-03-02', 'end_date' => '2026-03-06', 'status' => 'Approved']);
        Leave::create(['employee_id' => $alice->id, 'leave_type' => 'Annual Leave', 'start_date' => '2026-11-02', 'end_date' => '2026-11-03', 'status' => 'Pending']);
        Leave::create(['employee_id' => $alice->id, 'leave_type' => 'Annual Leave', 'start_date' => '2025-03-03', 'end_date' => '2025-03-03', 'status' => 'Approved']);

        $result = app(LeaveBalanceReport::class)->run($this->filters('2026-01-01', '2026-12-31'));
        $rows = collect($result->rows);

        // Only the active employee; no paternity leave for her, no balance for unpaid leave
        $this->assertSame(['Alice Test'], $rows->pluck('employee')->unique()->values()->all());
        $this->assertNull($rows->firstWhere('type', 'Paternity Leave'));
        $this->assertNull($rows->firstWhere('type', 'Unpaid Leave'));
        $this->assertNotNull($rows->firstWhere('type', 'Maternity Leave'));

        // Hired in 2018: 16 days of annual leave
        $annual = $rows->firstWhere('type', 'Annual Leave');
        $this->assertSame(16.0, $annual['entitlement']);
        $this->assertSame(5.0, $annual['taken']);
        $this->assertSame(2.0, $annual['pending']);
        $this->assertSame(9.0, $annual['remaining']);
    }

    public function test_claims_by_type_and_by_employee(): void
    {
        $this->seed(ClaimTypeSeeder::class);
        $sales = $this->department('Sales');
        $alice = $this->employee('alice@example.com', ['department_id' => $sales->id]);
        $bob = $this->employee('bob@example.com');

        $this->claim($alice, Claim::SUBMITTED, [['Parking & Toll', 50]]);
        $this->claim($alice, Claim::APPROVED, [['Parking & Toll', 30], ['Mileage', null, 100]]);
        $this->claim($bob, Claim::PAID, [['Meals', 20]]);
        // Not counted: a draft, a rejected claim, an item of another month
        $this->claim($bob, Claim::DRAFT, [['Meals', 100]]);
        $this->claim($bob, Claim::REJECTED, [['Meals', 70]]);
        $this->claim($bob, Claim::PAID, [['Meals', 40]], '2026-10-31');

        $filters = $this->filters('2026-11-01', '2026-11-30');
        $byType = collect((new ClaimsByTypeReport)->run($filters)->rows)->keyBy('type');

        $this->assertSame(['Meals', 'Parking & Toll', 'Mileage'], $byType->keys()->all());
        $this->assertSame(
            ['submitted' => 50.0, 'approved' => 30.0, 'paid' => 0.0, 'total' => 80.0],
            array_intersect_key($byType['Parking & Toll'], array_flip(['submitted', 'approved', 'paid', 'total'])),
        );
        // 100 km at 0.60
        $this->assertSame(60.0, $byType['Mileage']['total']);
        $this->assertSame(100.0, $byType['Mileage']['distance']);
        $this->assertNull($byType['Meals']['distance']);
        $this->assertSame(20.0, $byType['Meals']['paid']);

        $result = (new ClaimsByEmployeeReport)->run($filters);
        $byEmployee = collect($result->rows)->keyBy('employee');
        $this->assertSame(2, $byEmployee['Alice Test']['claims']);
        $this->assertSame(140.0, $byEmployee['Alice Test']['total']);
        $this->assertSame(20.0, $byEmployee['Bob Test']['total']);
        $this->assertSame(['Sales' => 140.0, 'No department' => 20.0], array_combine($result->chart->labels, $result->chart->values));

        $this->assertSame(['Alice Test'], array_column((new ClaimsByEmployeeReport)->run($this->filters('2026-11-01', '2026-11-30', $sales))->rows, 'employee'));
    }

    public function test_attendance_days_hours_and_late_arrivals(): void
    {
        $alice = $this->employee('alice@example.com');
        $shift = Shift::create(['name' => 'Day', 'start_time' => '09:00:00', 'end_time' => '17:00:00']);
        $night = Shift::create(['name' => 'Night', 'start_time' => '22:00:00', 'end_time' => '06:00:00']);
        Attendance::create(['employee_id' => $alice->id, 'date' => '2026-11-02', 'clock_in' => '08:55', 'clock_out' => '17:00', 'shift_id' => $shift->id]);
        Attendance::create(['employee_id' => $alice->id, 'date' => '2026-11-03', 'clock_in' => '09:15', 'clock_out' => '17:15', 'shift_id' => $shift->id]);
        Attendance::create(['employee_id' => $alice->id, 'date' => '2026-11-04', 'clock_in' => '09:00', 'shift_id' => $shift->id]);
        Attendance::create(['employee_id' => $alice->id, 'date' => '2026-11-05', 'clock_in' => '22:00', 'clock_out' => '06:00', 'shift_id' => $night->id]);
        Attendance::create(['employee_id' => $alice->id, 'date' => '2026-10-30', 'clock_in' => '09:00', 'clock_out' => '17:00', 'shift_id' => $shift->id]);

        $result = (new AttendanceSummaryReport)->run($this->filters('2026-11-01', '2026-11-30'));

        $this->assertSame([
            'code' => null, 'employee' => 'Alice Test', 'department' => null,
            'days' => 4, 'hours' => 24.08, 'average' => 8.03, 'late' => 1, 'no_clock_out' => 1,
        ], $result->rows[0]);
        // A point per day of November
        $this->assertSame('line', $result->chart->type);
        $this->assertCount(21, $result->chart->values); // the working days of November
        $this->assertSame(['Mon 2 Nov' => 1, 'Tue 3 Nov' => 1], array_slice(array_combine($result->chart->labels, $result->chart->values), 0, 2));

        // A long period: a bar per month
        $chart = (new AttendanceSummaryReport)->run($this->filters('2026-01-01', '2026-12-31'))->chart;
        $this->assertSame('bar', $chart->type);
        $this->assertSame([1, 4], [$chart->values[9], $chart->values[10]]);
    }

    public function test_the_last_day_of_the_period_counts(): void
    {
        // SQLite stores the date casts as "Y-m-d 00:00:00": a plain BETWEEN would drop the last day
        $alice = $this->employee('alice@example.com');
        $shift = Shift::create(['name' => 'Day', 'start_time' => '09:00:00', 'end_time' => '17:00:00']);
        Attendance::create(['employee_id' => $alice->id, 'date' => '2026-11-17', 'clock_in' => '09:00', 'clock_out' => '17:00', 'shift_id' => $shift->id]);
        Payroll::create(['employee_id' => $alice->id, 'period' => '2026-11', 'pay_date' => '2026-11-17', 'gross_pay' => 100, 'net_pay' => 90]);

        $filters = $this->filters('2026-11-01', '2026-11-17');

        $this->assertSame(1, (new AttendanceSummaryReport)->run($filters)->rows[0]['days'] ?? null);
        $this->assertCount(1, (new PayrollSummaryReport)->run($filters)->rows);
    }

    public function test_payroll_by_period(): void
    {
        $alice = $this->employee('alice@example.com');
        $bob = $this->employee('bob@example.com');
        $payslip = fn (Employee $employee, string $period, string $date, float $gross, float $net, string $status = 'completed') => Payroll::create([
            'employee_id' => $employee->id, 'period' => $period, 'pay_date' => $date, 'gross_pay' => $gross, 'net_pay' => $net, 'status' => $status,
        ]);
        $payslip($alice, '2026-10', '2026-10-28', 5000, 4200);
        $payslip($bob, '2026-10', '2026-10-28', 3000, 2600, 'pending');
        $payslip($alice, '2026-09', '2026-09-28', 5000, 4200);
        $payslip($bob, '2026-09', '2026-09-28', 3000, 2600, 'cancelled');

        $result = (new PayrollSummaryReport)->run($this->filters('2026-01-01', '2026-12-31'));

        $this->assertSame(['2026-09', '2026-10'], array_column($result->rows, 'period'));
        $this->assertSame(
            ['period' => '2026-10', 'pay_date' => '2026-10-28', 'payslips' => 2, 'gross' => 8000.0, 'deductions' => 1200.0, 'net' => 6800.0, 'pending' => 1],
            $result->rows[1],
        );
        $this->assertSame(1, $result->rows[0]['payslips']);
    }

    public function test_task_workload_today(): void
    {
        $sales = $this->department('Sales');
        $alice = $this->employee('alice@example.com', ['department_id' => $sales->id]);
        $task = fn (?Employee $assignee, string $status, ?string $due = null) => Task::create(['title' => 'Task', 'status' => $status, 'assignee_id' => $assignee?->id, 'due_date' => $due]);
        $task($alice, 'todo', '2026-11-17');
        $task($alice, 'todo', '2026-11-18');
        $task($alice, 'in_progress');
        $task($alice, 'completed', '2026-11-01');
        $task(null, 'todo', '2026-11-01');

        $report = new TaskWorkloadReport;
        $rows = collect($report->run($this->filters('2026-11-18', '2026-11-18'))->rows)->keyBy('employee');

        $this->assertSame(['todo' => 2, 'in_progress' => 1, 'open' => 3, 'overdue' => 1, 'completed' => 1], array_intersect_key($rows['Alice Test'], array_flip(['todo', 'in_progress', 'open', 'overdue', 'completed'])));
        $this->assertSame(1, $rows['Unassigned']['overdue']);

        // A department: no unassigned tasks
        $this->assertSame(['Alice Test'], array_column($report->run($this->filters('2026-11-18', '2026-11-18', $sales))->rows, 'employee'));
    }

    /**
     * A claim with items [type, amount, distance] dated 2026-11-10, then put in the given status.
     */
    private function claim(Employee $owner, string $status, array $items, string $date = '2026-11-10'): Claim
    {
        $claim = Claim::create(['employee_id' => $owner->id, 'title' => 'Claim']);
        foreach ($items as $item) {
            [$type, $amount, $distance] = array_pad($item, 3, null);
            ClaimItem::create([
                'claim_id' => $claim->id,
                'claim_type_id' => ClaimType::where('name', $type)->value('id'),
                'date' => $date,
                'description' => 'Item',
                'amount' => $amount ?? 0,
                'distance_km' => $distance ?? null,
            ]);
        }
        if ($status !== Claim::DRAFT) {
            $claim->update(['status' => $status, 'submitted_at' => now()]);
        }

        return $claim;
    }
}
