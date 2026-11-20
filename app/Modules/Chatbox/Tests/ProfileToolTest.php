<?php

namespace App\Modules\Chatbox\Tests;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Modules\Chatbox\ChatTools\ProfileTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEmployees;
use Tests\TestCase;

/**
 * The work profile the assistant may give an employee about themselves: only what they would say about
 * their job, and never the personal or sensitive parts of the employee record.
 */
class ProfileToolTest extends TestCase
{
    use CreatesEmployees;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdminPanel();
    }

    private function position(string $title, Department $department, int $salary): Position
    {
        $position = new Position;
        $position->forceFill(['title' => $title, 'department_id' => $department->id, 'salary' => $salary])->save();

        return $position;
    }

    public function test_it_gives_the_work_details_and_the_supervisor(): void
    {
        $boss = $this->makeEmployee('Boss');
        $department = Department::create(['name' => 'Sales', 'manager_id' => $boss->id]);
        $position = $this->position('Account Executive', $department, 7000);
        $alice = $this->makeEmployee('Alice', attributes: [
            'department_id' => $department->id,
            'position_id' => $position->id,
            'supervisor_id' => $boss->id,
            'hire_date' => now()->subYears(3)->toDateString(),
        ]);

        $result = (new ProfileTool)->run($alice, []);

        $this->assertSame('Alice Test', $result['name']);
        $this->assertSame('Sales', $result['department']);
        $this->assertSame('Account Executive', $result['position']);
        $this->assertSame('Permanent', $result['employment_type']);
        $this->assertSame('Boss Test', $result['supervisor']);
        $this->assertEqualsWithDelta(3.0, $result['years_of_service'], 0.1);
    }

    public function test_the_manager_of_the_department_is_the_supervisor_when_none_is_chosen(): void
    {
        $boss = $this->makeEmployee('Boss');
        $department = Department::create(['name' => 'Sales', 'manager_id' => $boss->id]);
        $alice = $this->makeEmployee('Alice', attributes: ['department_id' => $department->id]);

        $this->assertSame('Boss Test', (new ProfileTool)->run($alice, [])['supervisor']);
    }

    public function test_missing_details_are_left_out_rather_than_made_up(): void
    {
        $result = (new ProfileTool)->run($this->makeEmployee('Alice'), []);

        foreach (['department', 'position', 'supervisor', 'hire_date', 'years_of_service'] as $key) {
            $this->assertArrayNotHasKey($key, $result);
        }
    }

    public function test_nothing_personal_or_sensitive_is_ever_in_it(): void
    {
        $department = Department::create(['name' => 'Sales']);
        $position = $this->position('Executive', $department, 123456);
        $alice = $this->makeEmployee('Alice', attributes: [
            'department_id' => $department->id,
            'position_id' => $position->id,
            'national_id' => '900101015555',
            'phone' => '0123456789',
            'next_of_kin_name' => 'Kin Person',
            'next_of_kin_phone' => '0199999999',
            'date_of_birth' => '1990-01-01',
        ]);

        $json = json_encode((new ProfileTool)->run($alice, []));

        foreach (['900101015555', '0123456789', 'Kin Person', '0199999999', '1990-01-01', '123456', 'alice@example.com', 'password'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, $secret);
        }
    }

    public function test_the_employee_cannot_be_chosen_through_the_input(): void
    {
        $alice = $this->makeEmployee('Alice');
        $bob = $this->makeEmployee('Bob');

        $result = (new ProfileTool)->run($alice, ['employee_id' => $bob->id, 'name' => 'Bob Test']);

        $this->assertSame('Alice Test', $result['name']);
    }
}
