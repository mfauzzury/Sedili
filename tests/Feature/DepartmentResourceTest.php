<?php

namespace Tests\Feature;

use App\Filament\Resources\Departments\Pages\CreateDepartment;
use App\Filament\Resources\Departments\Pages\EditDepartment;
use App\Filament\Resources\Departments\Pages\ListDepartments;
use App\Models\Department;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesEmployees;
use Tests\TestCase;

class DepartmentResourceTest extends TestCase
{
    use CreatesEmployees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdminPanel();
    }

    public function test_an_admin_can_create_a_department(): void
    {
        $this->loginAsAdmin();

        Livewire::test(CreateDepartment::class)
            ->fillForm(['name' => 'Finance', 'code' => 'FIN', 'description' => 'Money matters'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('departments', ['name' => 'Finance', 'code' => 'FIN']);
    }

    public function test_the_name_is_required(): void
    {
        $this->loginAsAdmin();

        Livewire::test(CreateDepartment::class)
            ->fillForm(['name' => ''])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);
    }

    public function test_the_list_shows_the_manager_or_no_manager(): void
    {
        $this->loginAsAdmin();
        $manager = $this->makeEmployee('Maya');
        $managed = Department::create(['name' => 'Sales', 'manager_id' => $manager->id]);
        $unmanaged = Department::create(['name' => 'Ops']);

        Livewire::test(ListDepartments::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$managed, $unmanaged])
            ->assertSee('Maya Test')
            ->assertSee('No Manager');
    }

    public function test_the_list_can_be_searched_and_sorted_by_manager_name(): void
    {
        $this->loginAsAdmin();
        $maya = $this->makeEmployee('Maya');
        $zed = $this->makeEmployee('Zed');
        $a = Department::create(['name' => 'Sales', 'manager_id' => $zed->id]);
        $b = Department::create(['name' => 'Ops', 'manager_id' => $maya->id]);

        Livewire::test(ListDepartments::class)
            ->searchTable('Maya')
            ->assertCanSeeTableRecords([$b])
            ->assertCanNotSeeTableRecords([$a]);

        Livewire::test(ListDepartments::class)
            ->sortTable('manager.name')
            ->assertCanSeeTableRecords([$b, $a], inOrder: true);
    }

    public function test_an_admin_can_edit_and_delete_a_department(): void
    {
        $this->loginAsAdmin();
        $department = Department::create(['name' => 'Sales']);

        Livewire::test(EditDepartment::class, ['record' => $department->getKey()])
            ->fillForm(['name' => 'Revenue'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Revenue', $department->fresh()->name);

        Livewire::test(EditDepartment::class, ['record' => $department->getKey()])
            ->callAction(DeleteAction::class);
        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
    }

    public function test_the_manager_is_told_when_they_are_assigned_a_department(): void
    {
        $this->loginAsAdmin();
        $manager = $this->makeEmployee('Maya');

        $department = Department::create(['name' => 'Sales', 'manager_id' => $manager->id]);

        $this->assertSame(1, $manager->notifications()->count());
        $this->assertStringContainsString('assigned a department', json_encode($manager->notifications()->first()->data));

        // A manager given later is told too
        $other = $this->makeEmployee('Omar');
        $department->update(['manager_id' => $other->id]);
        $this->assertSame(1, $other->notifications()->count());
        $this->assertSame(1, $manager->notifications()->count());
    }

    public function test_editing_something_else_does_not_tell_the_manager_again(): void
    {
        $this->loginAsAdmin();
        $manager = $this->makeEmployee('Maya');
        $department = Department::create(['name' => 'Sales', 'manager_id' => $manager->id]);
        $this->assertSame(1, $manager->notifications()->count());

        $department->update(['description' => 'Now with a description']);
        $department->update(['name' => 'Revenue']);

        $this->assertSame(1, $manager->notifications()->count());
    }

    public function test_a_department_without_a_manager_notifies_nobody(): void
    {
        $this->loginAsAdmin();

        Department::create(['name' => 'Ops']);

        $this->assertSame(0, \Illuminate\Notifications\DatabaseNotification::count());
    }

    public function test_an_employee_cannot_open_the_department_screens(): void
    {
        $this->actingAs($this->makeEmployee('Alice'));

        $this->get('/departments')->assertForbidden();
    }
}
