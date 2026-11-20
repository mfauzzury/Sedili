<?php

namespace Tests\Feature;

use App\Filament\Employee\Widgets\MyTodos;
use App\Models\Department;
use App\Models\Employee;
use App\Modules\Inbox\Models\Message;
use App\Modules\Inbox\Models\Topic;
use App\Modules\Leave\Models\Leave;
use App\Modules\Module;
use App\Modules\ModuleManager;
use App\Modules\Tasks\Models\Task;
use App\Modules\Todo;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesEmployees;
use Tests\TestCase;

class TodoTest extends TestCase
{
    use CreatesEmployees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Filament::setCurrentPanel('portal');
    }

    private function todos(Employee $employee): array
    {
        return app(ModuleManager::class)->todos($employee, 'portal');
    }

    private function titles(array $groups, string $module): array
    {
        return array_map(fn(Todo $todo) => $todo->title, $groups[$module]['todos'] ?? []);
    }

    private function task(Employee $assignee, string $title, string $status = 'todo', ?int $days = null): Task
    {
        return Task::withoutEvents(fn() => Task::create([
            'title' => $title,
            'status' => $status,
            'assignee_id' => $assignee->id,
            'due_date' => $days === null ? null : now()->addDays($days)->toDateString(),
            'position' => 1,
        ]));
    }

    public function test_nothing_to_do_gives_an_empty_list_and_an_empty_state(): void
    {
        $alice = $this->makeEmployee('Alice');

        $this->assertSame([], $this->todos($alice));

        $this->actingAs($alice);
        Livewire::test(MyTodos::class)->assertSee('You are all caught up');
    }

    public function test_the_open_tasks_of_the_employee_come_by_due_date_and_late_ones_are_flagged(): void
    {
        $alice = $this->makeEmployee('Alice');
        $bob = $this->makeEmployee('Bob');
        $this->task($alice, 'No date');
        $this->task($alice, 'Later', days: 9);
        $this->task($alice, 'Late', 'in_progress', -2);
        $this->task($alice, 'Today', days: 0);
        $this->task($alice, 'Done', 'completed', -5);
        $this->task($bob, 'Not mine', days: 0);

        $groups = $this->todos($alice);

        $this->assertSame(['Late', 'Today', 'Later', 'No date'], $this->titles($groups, 'tasks'));
        [$late, $today, $later, $none] = $groups['tasks']['todos'];
        $this->assertSame(['Overdue by 2 days', 'danger', 'In progress'], [$late->badge, $late->color, $late->detail]);
        $this->assertSame(['Due today', 'warning'], [$today->badge, $today->color]);
        $this->assertSame('gray', $later->color);
        $this->assertNull($none->badge);
    }

    public function test_many_tasks_end_with_one_line_for_the_rest(): void
    {
        $alice = $this->makeEmployee('Alice');
        foreach (range(1, 11) as $n) {
            $this->task($alice, "Task $n", days: $n);
        }

        $titles = $this->titles($this->todos($alice), 'tasks');

        $this->assertCount(9, $titles);
        $this->assertSame('3 more open tasks', end($titles));
    }

    public function test_unread_messages_are_one_line(): void
    {
        $alice = $this->makeEmployee('Alice');
        $admin = $this->makeEmployee('Boss', 'admin');
        $topic = Topic::create(['subject' => 'Hello', 'creator_id' => $admin->id, 'receiver_id' => $alice->id]);
        Message::withoutEvents(function () use ($topic, $admin, $alice) {
            foreach (['one', 'two'] as $content) {
                Message::create(['topic_id' => $topic->id, 'sender_id' => $admin->id, 'receiver_id' => $alice->id, 'content' => $content]);
            }
            Message::create(['topic_id' => $topic->id, 'sender_id' => $admin->id, 'receiver_id' => $alice->id, 'content' => 'read', 'read_at' => now()]);
        });

        $groups = $this->todos($alice);

        $this->assertSame(['2 unread messages'], $this->titles($groups, 'inbox'));
        $this->assertSame('Latest from Boss Test', $groups['inbox']['todos'][0]->detail);
        // The sender has nothing unread
        $this->assertArrayNotHasKey('inbox', $this->todos($admin));
    }

    public function test_a_supervisor_sees_the_leave_requests_to_approve(): void
    {
        $dept = Department::create(['name' => 'Engineering']);
        $boss = $this->makeEmployee('Boss', attributes: ['department_id' => $dept->id]);
        $alice = $this->makeEmployee('Alice', attributes: ['department_id' => $dept->id, 'supervisor_id' => $boss->id]);
        $dept->forceFill(['manager_id' => $boss->id])->save();
        $leave = fn(string $status) => Leave::create([
            'employee_id' => $alice->id, 'leave_type' => 'Annual Leave', 'status' => $status,
            'start_date' => '2026-12-07', 'end_date' => '2026-12-08',
        ]);
        $leave('Pending');
        $leave('Approved');

        $this->assertSame(['1 leave request to approve'], $this->titles($this->todos($boss), 'leave'));
        $this->assertSame('Alice', $this->todos($boss)['leave']['todos'][0]->detail);
        // Alice asked for it, so there is nothing for her to approve
        $this->assertArrayNotHasKey('leave', $this->todos($alice));
    }

    public function test_a_disabled_module_adds_nothing(): void
    {
        $alice = $this->makeEmployee('Alice');
        $this->task($alice, 'Open task');
        $this->assertArrayHasKey('tasks', $this->todos($alice));

        app(ModuleManager::class)->setEnabled('tasks', false);

        $this->assertArrayNotHasKey('tasks', $this->todos($alice));
    }

    public function test_a_module_that_fails_does_not_break_the_list(): void
    {
        $broken = new class extends Module {
            public function key(): string { return 'broken'; }
            public function label(): string { return 'Broken'; }
            public function todos(Employee $employee, string $panelId): array { throw new \RuntimeException('boom'); }
        };
        $fine = new class extends Module {
            public function key(): string { return 'fine'; }
            public function label(): string { return 'Fine'; }
            public function todos(Employee $employee, string $panelId): array { return [new Todo('Do it', '/x')]; }
        };
        $manager = new class([$broken, $fine]) extends ModuleManager {
            public function __construct(private array $list) {}
            public function enabled(): array { return $this->list; }
        };

        $groups = $manager->todos($this->makeEmployee('Alice'), 'portal');

        $this->assertSame(['fine'], array_keys($groups));
    }

    public function test_the_widget_lists_the_groups_with_the_most_urgent_first(): void
    {
        $alice = $this->makeEmployee('Alice');
        $admin = $this->makeEmployee('Boss', 'admin');
        $topic = Topic::create(['subject' => 'Hello', 'creator_id' => $admin->id, 'receiver_id' => $alice->id]);
        Message::withoutEvents(fn() => Message::create(['topic_id' => $topic->id, 'sender_id' => $admin->id, 'receiver_id' => $alice->id, 'content' => 'hi']));
        $this->task($alice, 'Overdue thing', days: -1);
        $this->actingAs($alice);

        $widget = Livewire::test(MyTodos::class);

        $widget->assertSee('Overdue thing')->assertSee('1 unread message')->assertDontSee('You are all caught up');
        $this->assertSame(['tasks', 'inbox'], array_keys($widget->instance()->getGroups()));
    }
}
