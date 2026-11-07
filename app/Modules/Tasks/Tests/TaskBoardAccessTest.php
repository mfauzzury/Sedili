<?php

namespace App\Modules\Tasks\Tests;

use App\Models\Employee;
use App\Modules\Tasks\Filament\Pages\TaskBoard;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskBoardAccessTest extends TestCase
{
    use RefreshDatabase;

    private Employee $alice;
    private Employee $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->alice = $this->employee('alice@example.com');
        $this->bob = $this->employee('bob@example.com');
        Task::create(['title' => 'Alice task', 'status' => 'todo', 'assignee_id' => $this->alice->id, 'position' => 1]);
        Task::create(['title' => 'Bob task', 'status' => 'todo', 'assignee_id' => $this->bob->id, 'position' => 2]);
    }

    private function employee(string $email): Employee
    {
        $employee = new Employee();
        $employee->forceFill([
            'first_name' => 'Test',
            'last_name' => $email,
            'email' => $email,
            'password' => 'password',
            'employment_type' => 'Permanent',
        ])->save();
        return $employee;
    }

    public function test_an_employee_only_sees_their_own_tasks_in_the_portal(): void
    {
        Filament::setCurrentPanel('portal');
        $this->actingAs($this->alice);

        $titles = (new TaskBoard())->getEloquentQuery()->pluck('title')->all();

        $this->assertSame(['Alice task'], $titles);
    }

    public function test_an_employee_cannot_delete_tasks_in_the_portal(): void
    {
        Filament::setCurrentPanel('portal');
        $this->actingAs($this->alice);

        $this->assertFalse((new TaskBoard())->isAdminPanel());
    }

    public function test_an_admin_sees_every_task(): void
    {
        $this->alice->assignRole('admin');
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->alice);

        $board = new TaskBoard();
        $this->assertTrue($board->isAdminPanel());
        $this->assertEqualsCanonicalizing(['Alice task', 'Bob task'], $board->getEloquentQuery()->pluck('title')->all());
    }
}
