<?php

namespace Tests\Feature;

use App\Filament\Resources\Admins\Pages\CreateAdmin;
use App\Filament\Resources\Admins\Pages\ListAdmins;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Concerns\CreatesEmployees;
use Tests\TestCase;

class AdminResourceTest extends TestCase
{
    use CreatesEmployees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdminPanel();
    }

    public function test_an_admin_can_create_another_admin_who_gets_the_admin_role(): void
    {
        $this->loginAsAdmin();

        Livewire::test(CreateAdmin::class)
            ->fillForm($this->employeeFormData(['email' => 'second@example.com']))
            ->call('create')
            ->assertHasNoFormErrors();

        $second = Employee::where('email', 'second@example.com')->firstOrFail();
        $this->assertTrue($second->hasRole('admin'));
        $this->assertTrue($second->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')));
    }

    public function test_a_new_admin_cannot_log_in_with_their_email_as_password(): void
    {
        $this->loginAsAdmin();

        Livewire::test(CreateAdmin::class)
            ->fillForm($this->employeeFormData(['email' => 'second@example.com']))
            ->call('create')
            ->assertHasNoFormErrors();

        $second = Employee::where('email', 'second@example.com')->firstOrFail();
        $this->assertFalse(Hash::check('second@example.com', $second->password));
    }

    public function test_the_list_shows_the_admins_and_not_the_employees(): void
    {
        $admin = $this->loginAsAdmin();
        $alice = $this->makeEmployee('Alice');

        Livewire::test(ListAdmins::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$admin])
            ->assertCanNotSeeTableRecords([$alice]);
    }

    public function test_an_employee_cannot_open_the_admin_screens(): void
    {
        $this->actingAs($this->makeEmployee('Alice'));

        $this->get('/admins')->assertForbidden();
        $this->get('/admins/create')->assertForbidden();
    }
}
