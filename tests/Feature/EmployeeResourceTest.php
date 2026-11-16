<?php

namespace Tests\Feature;

use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Concerns\CreatesEmployees;
use Tests\TestCase;

class EmployeeResourceTest extends TestCase
{
    use CreatesEmployees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdminPanel();
    }

    public function test_an_admin_can_create_an_employee_who_gets_the_employee_role(): void
    {
        $this->loginAsAdmin();

        Livewire::test(CreateEmployee::class)
            ->fillForm($this->employeeFormData(['email' => 'nora@example.com']))
            ->call('create')
            ->assertHasNoFormErrors();

        $nora = Employee::where('email', 'nora@example.com')->firstOrFail();
        $this->assertTrue($nora->hasRole('employee'));
        $this->assertFalse($nora->hasRole('admin'));
        $this->assertTrue((bool) $nora->is_active);
    }

    public function test_a_new_employee_cannot_log_in_with_their_email_as_password(): void
    {
        $this->loginAsAdmin();

        Livewire::test(CreateEmployee::class)
            ->fillForm($this->employeeFormData(['email' => 'nora@example.com']))
            ->call('create')
            ->assertHasNoFormErrors();

        // They choose their own password with "Forgot password"; nothing derived from public data works
        $nora = Employee::where('email', 'nora@example.com')->firstOrFail();
        $this->assertFalse(Hash::check('nora@example.com', $nora->password));
        $this->assertFalse(Hash::check('password', $nora->password));
    }

    public function test_the_required_fields_and_unique_values_are_enforced(): void
    {
        $this->loginAsAdmin();
        $existing = $this->makeEmployee('Taken', attributes: ['phone' => '0100000000', 'national_id' => 111]);

        Livewire::test(CreateEmployee::class)
            ->fillForm($this->employeeFormData([
                'first_name' => '',
                'email' => $existing->email,
                'phone' => '0100000000',
                'national_id' => 111,
            ]))
            ->call('create')
            ->assertHasFormErrors([
                'first_name' => 'required',
                'email' => 'unique',
                'phone' => 'unique',
                'national_id' => 'unique',
            ]);
    }

    public function test_the_list_shows_the_employees_and_not_the_admins(): void
    {
        $admin = $this->loginAsAdmin();
        $alice = $this->makeEmployee('Alice');
        $bob = $this->makeEmployee('Bob');

        Livewire::test(ListEmployees::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$alice, $bob])
            ->assertCanNotSeeTableRecords([$admin]);
    }

    public function test_the_list_filters_active_and_inactive_employees(): void
    {
        $this->loginAsAdmin();
        $active = $this->makeEmployee('Active');
        $inactive = $this->makeEmployee('Gone', attributes: ['is_active' => false]);

        Livewire::test(ListEmployees::class)
            ->filterTable('is_inactive')
            ->assertCanSeeTableRecords([$inactive])
            ->assertCanNotSeeTableRecords([$active]);

        Livewire::test(ListEmployees::class)
            ->filterTable('is_active')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive]);
    }

    public function test_an_admin_can_edit_an_employee(): void
    {
        $this->loginAsAdmin();
        $alice = $this->makeEmployee('Alice', attributes: $this->employeeFormData());

        Livewire::test(EditEmployee::class, ['record' => $alice->getKey()])
            ->fillForm(['first_name' => 'Alicia', 'employment_type' => 'Contract'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Alicia', $alice->fresh()->first_name);
        $this->assertSame('Contract', $alice->fresh()->employment_type);
    }

    public function test_opening_an_employee_shows_the_edit_form_to_someone_who_can_edit(): void
    {
        $this->loginAsAdmin();
        $employee = $this->makeEmployee('Opened');

        Livewire::test(ViewEmployee::class, ['record' => $employee->getKey()])
            ->assertRedirect(EmployeeResource::getUrl('edit', ['record' => $employee]));
    }

    public function test_the_save_and_create_buttons_are_black_and_delete_is_a_red_outline(): void
    {
        $this->loginAsAdmin();
        $employee = $this->makeEmployee('Styled');

        $edit = Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])->html();
        $this->assertMatchesRegularExpression('/<button[^>]*fi-color-black[^>]*>\s*(<[^>]+>\s*)*Save changes/s', $edit);
        $this->assertMatchesRegularExpression('/<button[^>]*fi-outlined[^>]*fi-color-danger[^>]*>/s', $edit);

        $create = Livewire::test(CreateEmployee::class)->html();
        $this->assertMatchesRegularExpression('/<button[^>]*fi-color-black[^>]*>\s*(<[^>]+>\s*)*Create/s', $create);
    }

    public function test_an_employee_cannot_report_to_someone_who_reports_to_them(): void
    {
        $this->loginAsAdmin();
        $boss = $this->makeEmployee('Boss', attributes: $this->employeeFormData());
        $alice = $this->makeEmployee('Alice', attributes: [...$this->employeeFormData(), 'supervisor_id' => $boss->id]);

        // Boss reporting to Alice would close a loop
        Livewire::test(EditEmployee::class, ['record' => $boss->getKey()])
            ->fillForm(['supervisor_id' => $alice->id])
            ->call('save')
            ->assertHasFormErrors(['supervisor_id']);

        $this->assertNull($boss->fresh()->supervisor_id);
    }

    public function test_an_employee_cannot_be_their_own_supervisor_in_the_form(): void
    {
        $this->loginAsAdmin();
        $alice = $this->makeEmployee('Alice', attributes: $this->employeeFormData());

        Livewire::test(EditEmployee::class, ['record' => $alice->getKey()])
            ->fillForm(['supervisor_id' => $alice->id])
            ->call('save')
            ->assertHasFormErrors(['supervisor_id']);
    }

    public function test_an_employee_cannot_open_the_employee_screens(): void
    {
        $this->actingAs($this->makeEmployee('Alice'));

        $this->get('/employees')->assertForbidden();
        $this->get('/employees/create')->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/employees')->assertRedirect();
    }
}
