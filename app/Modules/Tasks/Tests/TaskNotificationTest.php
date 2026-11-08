<?php

namespace App\Modules\Tasks\Tests;

use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEmployees;
use Tests\TestCase;

class TaskNotificationTest extends TestCase
{
    use CreatesEmployees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdminPanel();
        $this->loginAsAdmin();
    }

    private function titles($employee): array
    {
        return $employee->notifications()->get()->map(fn ($n) => $n->data['title'])->all();
    }

    public function test_the_assignee_is_told_about_a_new_task(): void
    {
        $alice = $this->makeEmployee('Alice');

        Task::create(['title' => 'Write report', 'status' => 'todo', 'assignee_id' => $alice->id, 'position' => 1]);

        $this->assertSame(['New Task Assigned'], $this->titles($alice));
    }

    public function test_nobody_is_told_about_a_task_without_assignee(): void
    {
        Task::create(['title' => 'Unowned', 'status' => 'todo', 'position' => 1]);

        $this->assertSame(0, \Illuminate\Notifications\DatabaseNotification::count());
    }

    public function test_moving_cards_on_the_board_does_not_notify_the_assignees(): void
    {
        $alice = $this->makeEmployee('Alice');
        $task = Task::create(['title' => 'Write report', 'status' => 'todo', 'assignee_id' => $alice->id, 'position' => 1]);

        // Dragging a card re-numbers the positions of the cards around it
        $task->update(['position' => 5]);
        $task->update(['sort_order' => 3]);

        $this->assertSame(['New Task Assigned'], $this->titles($alice));
    }

    public function test_the_assignee_is_told_when_the_task_changes_column_or_content(): void
    {
        $alice = $this->makeEmployee('Alice');
        $task = Task::create(['title' => 'Write report', 'status' => 'todo', 'assignee_id' => $alice->id, 'position' => 1]);

        $task->update(['status' => 'completed']);
        $task->update(['title' => 'Write the report']);

        $this->assertEqualsCanonicalizing(['New Task Assigned', 'Task Updated', 'Task Updated'], $this->titles($alice));
    }

    public function test_a_reassigned_task_tells_the_new_assignee_and_not_the_previous_one(): void
    {
        $alice = $this->makeEmployee('Alice');
        $bob = $this->makeEmployee('Bob');
        $task = Task::create(['title' => 'Write report', 'status' => 'todo', 'assignee_id' => $alice->id, 'position' => 1]);

        $task->update(['assignee_id' => $bob->id]);

        $this->assertSame(['New Task Assigned'], $this->titles($alice));
        $this->assertSame(['New Task Assigned'], $this->titles($bob));
    }
}
