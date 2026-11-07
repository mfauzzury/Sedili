<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Carbon\Carbon;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeaveService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeaveTypeSeeder::class);
        $this->service = app(LeaveService::class);
    }

    private function employee(array $attributes = []): Employee
    {
        static $n = 0;
        $n++;
        $employee = new Employee();
        $employee->forceFill(array_merge([
            'first_name' => 'Test',
            'last_name' => "User$n",
            'email' => "user$n@example.com",
            'password' => 'password',
            'employment_type' => 'Permanent',
            'gender' => 'Male',
            'hire_date' => '2020-01-01',
        ], $attributes))->save();
        return $employee;
    }

    private function leave(Employee $employee, string $type, string $start, string $end, string $status = 'Approved'): Leave
    {
        return Leave::create([
            'employee_id' => $employee->id,
            'leave_type' => $type,
            'start_date' => $start,
            'end_date' => $end,
            'status' => $status,
        ]);
    }

    public function test_weekends_are_not_counted(): void
    {
        // Monday 2 to Sunday 8 March 2026
        $this->assertSame(5.0, $this->service->countDays(Carbon::parse('2026-03-02'), Carbon::parse('2026-03-08')));
    }

    public function test_a_single_day_counts_one_day(): void
    {
        $this->assertSame(1.0, $this->service->countDays(Carbon::parse('2026-03-02'), Carbon::parse('2026-03-02')));
    }

    public function test_public_holidays_are_not_counted(): void
    {
        Holiday::create(['date' => '2026-03-04', 'name' => 'Test holiday']);
        $this->assertSame(4.0, $this->service->countDays(Carbon::parse('2026-03-02'), Carbon::parse('2026-03-06')));
    }

    public function test_calendar_days_include_weekends(): void
    {
        $this->assertSame(7.0, $this->service->countDays(Carbon::parse('2026-03-02'), Carbon::parse('2026-03-08'), true));
    }

    public function test_days_are_stored_when_a_leave_is_saved(): void
    {
        $employee = $this->employee();
        $leave = $this->leave($employee, 'Annual Leave', '2026-03-02', '2026-03-06');
        $this->assertSame(5.0, $leave->fresh()->days);
        $this->assertSame(5.0, $leave->fresh()->duration);
    }

    public function test_entitlement_depends_on_years_of_service(): void
    {
        $annual = LeaveType::where('name', 'Annual Leave')->first();
        $this->assertSame(8.0, $this->service->entitlement($this->employee(['hire_date' => '2025-06-01']), $annual, 2026));
        $this->assertSame(12.0, $this->service->entitlement($this->employee(['hire_date' => '2023-06-01']), $annual, 2026));
        $this->assertSame(16.0, $this->service->entitlement($this->employee(['hire_date' => '2018-06-01']), $annual, 2026));
        $this->assertSame(8.0, $this->service->entitlement($this->employee(['hire_date' => null]), $annual, 2026));
    }

    public function test_unpaid_leave_has_no_limit(): void
    {
        $unpaid = LeaveType::where('name', 'Unpaid Leave')->first();
        $this->assertNull($this->service->entitlement($this->employee(), $unpaid, 2026));
        $this->assertNull($this->service->balance($this->employee(), $unpaid, 2026));
    }

    public function test_balance_deducts_pending_and_approved_leaves_only(): void
    {
        $employee = $this->employee(['hire_date' => '2025-06-01']); // 8 days
        $annual = LeaveType::where('name', 'Annual Leave')->first();
        $this->leave($employee, 'Annual Leave', '2026-03-02', '2026-03-04', 'Approved'); // 3
        $this->leave($employee, 'Annual Leave', '2026-04-06', '2026-04-07', 'Pending'); // 2
        $this->leave($employee, 'Annual Leave', '2026-05-04', '2026-05-08', 'Rejected'); // not counted
        $this->assertSame(3.0, $this->service->balance($employee, $annual, 2026));
    }

    public function test_a_valid_request_has_no_error(): void
    {
        $employee = $this->employee();
        $this->assertSame([], $this->service->validate($employee->id, 'Annual Leave', '2026-03-02', '2026-03-03'));
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        $employee = $this->employee();
        $errors = $this->service->validate($employee->id, 'Annual Leave', '2026-03-05', '2026-03-03');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('end date', $errors[0]);
    }

    public function test_overlapping_requests_are_rejected(): void
    {
        $employee = $this->employee();
        $existing = $this->leave($employee, 'Annual Leave', '2026-03-02', '2026-03-04');
        $errors = $this->service->validate($employee->id, 'Sick Leave', '2026-03-04', '2026-03-05');
        $this->assertStringContainsString('already has', implode(' ', $errors));
        // Editing the same request is not an overlap with itself
        $this->assertSame([], $this->service->validate($employee->id, 'Annual Leave', '2026-03-02', '2026-03-04', 'Approved', $existing->id));
    }

    public function test_request_above_the_balance_is_rejected(): void
    {
        $employee = $this->employee(['hire_date' => '2025-06-01']); // 8 days
        $this->leave($employee, 'Annual Leave', '2026-03-02', '2026-03-06'); // 5 used
        $errors = $this->service->validate($employee->id, 'Annual Leave', '2026-04-06', '2026-04-10'); // 5 more
        $this->assertStringContainsString('Not enough Annual Leave balance', implode(' ', $errors));
    }

    public function test_gender_restricted_types_are_enforced(): void
    {
        $man = $this->employee(['gender' => 'Male']);
        $woman = $this->employee(['gender' => 'Female']);
        $this->assertStringContainsString('only available to Female', implode(' ', $this->service->validate($man->id, 'Maternity Leave', '2026-03-02', '2026-03-10')));
        $this->assertSame([], $this->service->validate($woman->id, 'Maternity Leave', '2026-03-02', '2026-03-10'));
        $this->assertStringContainsString('only available to Male', implode(' ', $this->service->validate($woman->id, 'Paternity Leave', '2026-03-02', '2026-03-04')));
    }

    public function test_a_period_without_working_day_is_rejected(): void
    {
        $employee = $this->employee();
        $errors = $this->service->validate($employee->id, 'Annual Leave', '2026-03-07', '2026-03-08'); // Saturday and Sunday
        $this->assertStringContainsString('no working day', implode(' ', $errors));
    }

    public function test_a_rejected_request_is_not_validated(): void
    {
        $employee = $this->employee();
        $this->assertSame([], $this->service->validate($employee->id, 'Annual Leave', '2026-03-05', '2026-03-03', 'Rejected'));
    }
}
