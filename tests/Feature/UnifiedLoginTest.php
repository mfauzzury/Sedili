<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Models\Employee;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesEmployees;
use Tests\TestCase;

/**
 * Admins and employees sign in on the same page (/login); where they land depends on their role.
 */
class UnifiedLoginTest extends TestCase
{
    use CreatesEmployees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdminPanel();
    }

    private function signIn(Employee $employee, string $password = 'password')
    {
        return Livewire::test(Login::class)
            ->fillForm(['email' => $employee->email, 'password' => $password])
            ->call('authenticate');
    }

    private function adminHome(): string
    {
        return Filament::getPanel('admin')->getUrl();
    }

    private function portalHome(): string
    {
        return Filament::getPanel('portal')->getUrl();
    }

    public function test_an_admin_lands_on_the_admin_panel(): void
    {
        $this->signIn($this->makeEmployee('Boss', 'admin'))->assertHasNoFormErrors()->assertRedirect($this->adminHome());
    }

    public function test_an_employee_can_sign_in_on_the_same_page_and_lands_on_the_portal(): void
    {
        $alice = $this->makeEmployee('Alice');

        $this->signIn($alice)->assertHasNoFormErrors()->assertRedirect($this->portalHome());
        $this->assertAuthenticatedAs($alice);
    }

    public function test_a_wrong_password_or_an_inactive_employee_cannot_sign_in(): void
    {
        $alice = $this->makeEmployee('Alice');
        $gone = $this->makeEmployee('Gone', attributes: ['is_active' => false]);

        $this->signIn($alice, 'wrong')->assertHasFormErrors()->assertNoRedirect();
        $this->signIn($gone)->assertHasFormErrors()->assertNoRedirect();
        $this->assertGuest();
    }

    public function test_the_page_wanted_before_signing_in_is_kept_when_it_is_allowed(): void
    {
        $portalPage = url('/portal/my-leave');

        // An employee gets the portal page they asked for ...
        session(['url.intended' => $portalPage]);
        $this->signIn($this->makeEmployee('Alice'))->assertRedirect($portalPage);

        // ... but not an admin page they cannot open: they land on the portal
        auth()->logout();
        session(['url.intended' => url('/employees')]);
        $this->signIn($this->makeEmployee('Bob'))->assertRedirect($this->portalHome());

        // An admin gets any page they asked for
        auth()->logout();
        session(['url.intended' => url('/employees')]);
        $this->signIn($this->makeEmployee('Boss', 'admin'))->assertRedirect(url('/employees'));
    }

    public function test_the_portal_login_address_leads_to_the_single_login_page(): void
    {
        $this->get('/portal/login')->assertRedirect('/login');
        $this->get('/login')->assertOk();
    }

    public function test_somebody_who_is_already_signed_in_is_sent_to_their_panel(): void
    {
        $this->actingAs($this->makeEmployee('Alice'));
        Livewire::test(Login::class)->assertRedirect($this->portalHome());

        $this->actingAs($this->makeEmployee('Boss', 'admin'));
        Livewire::test(Login::class)->assertRedirect($this->adminHome());
    }

    public function test_an_employee_is_still_kept_out_of_the_admin_panel(): void
    {
        $this->actingAs($this->makeEmployee('Alice'));

        $this->get('/employees')->assertForbidden();
    }

    public function test_the_login_page_has_the_split_layout_with_a_welcome(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Welcome back')
            ->assertSee('auth-split', false)
            ->assertSee('auth-visual', false);
    }

    public function test_the_sign_in_button_is_black(): void
    {
        $html = Livewire::test(Login::class)->html();

        $this->assertMatchesRegularExpression('/<button[^>]*fi-color-black[^>]*>\s*(<[^>]+>\s*)*Sign in/s', $html);
    }
}
