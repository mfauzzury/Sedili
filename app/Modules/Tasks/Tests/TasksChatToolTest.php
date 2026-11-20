<?php

namespace App\Modules\Tasks\Tests;

use App\Models\Employee;
use App\Modules\Tasks\ChatTools\MyTasksTool;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEmployees;
use Tests\TestCase;

/**
 * What the Tasks module lets the chat assistant look up: the tasks assigned to the employee who is
 * chatting, and nobody else's.
 */
class TasksChatToolTest extends TestCase
{
    use CreatesEmployees;
    use RefreshDatabase;

    private Employee $alice;

    private Employee $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->alice = $this->makeEmployee('Alice');
        $this->bob = $this->makeEmployee('Bob');
    }

    private function task(Employee $assignee, string $title, string $status = 'todo', ?string $due = null, ?string $description = null): Task
    {
        return Task::create(['title' => $title, 'status' => $status, 'assignee_id' => $assignee->id, 'due_date' => $due, 'description' => $description, 'position' => 1]);
    }

    public function test_the_open_tasks_come_soonest_due_first_with_the_days_until_they_are_due(): void
    {
        $this->task($this->alice, 'Later', 'todo', now()->addDays(10)->toDateString());
        $this->task($this->alice, 'Overdue', 'in_progress', now()->subDays(2)->toDateString());
        $this->task($this->alice, 'No date', 'todo');
        $this->task($this->alice, 'Today', 'todo', now()->toDateString());

        $result = (new MyTasksTool)->run($this->alice, []);

        $this->assertSame(['Overdue', 'Today', 'Later', 'No date'], array_column($result['tasks'], 'title'));
        $this->assertSame([-2, 0, 10], array_column(array_slice($result['tasks'], 0, 3), 'days_until_due'));
        $this->assertArrayNotHasKey('days_until_due', $result['tasks'][3]);
        $this->assertSame('In progress', $result['tasks'][0]['status']);
        $this->assertSame(4, $result['open_tasks']);
    }

    public function test_completed_tasks_are_left_out_unless_asked_for(): void
    {
        $this->task($this->alice, 'Open', 'todo');
        $this->task($this->alice, 'Done', 'completed', now()->subDay()->toDateString());

        $this->assertSame(['Open'], array_column((new MyTasksTool)->run($this->alice, [])['tasks'], 'title'));

        $all = (new MyTasksTool)->run($this->alice, ['include_completed' => true]);
        $this->assertSame(['Open', 'Done'], array_column($all['tasks'], 'title'), 'completed ones come last');
        $this->assertSame(1, $all['open_tasks']);
        $this->assertArrayNotHasKey('days_until_due', $all['tasks'][1], 'a finished task is not overdue');
    }

    public function test_only_the_tasks_of_the_employee_are_given_whatever_the_input_says(): void
    {
        $this->task($this->alice, 'Mine');
        $this->task($this->bob, "Bob's secret task");
        Task::create(['title' => 'Nobody', 'status' => 'todo', 'position' => 1]);

        $result = (new MyTasksTool)->run($this->alice, ['assignee_id' => $this->bob->id, 'employee_id' => $this->bob->id]);

        $this->assertSame(['Mine'], array_column($result['tasks'], 'title'));
    }

    public function test_the_description_is_cut_and_stripped_of_markup(): void
    {
        $this->task($this->alice, 'Report', 'todo', null, '<p>Start</p><script>alert(1)</script>'.str_repeat('x', 500));

        $description = (new MyTasksTool)->run($this->alice, [])['tasks'][0]['description'];

        $this->assertLessThanOrEqual(203, mb_strlen($description), 'cut at 200 characters, then "..."');
        $this->assertStringEndsWith('...', $description);
        $this->assertStringNotContainsString('<script>', $description);
        $this->assertStringNotContainsString('<p>', $description);
    }

    public function test_a_non_boolean_include_completed_does_not_count_as_true(): void
    {
        $this->task($this->alice, 'Done', 'completed');

        $this->assertSame([], (new MyTasksTool)->run($this->alice, ['include_completed' => 'false'])['tasks']);
    }

    public function test_at_most_twenty_tasks_come_back(): void
    {
        foreach (range(1, 25) as $i) {
            $this->task($this->alice, "Task $i");
        }

        $result = (new MyTasksTool)->run($this->alice, []);

        $this->assertCount(20, $result['tasks']);
        $this->assertSame(25, $result['open_tasks'], 'the count is of all of them');
    }
}
