<?php

namespace App\Modules\MicrosoftLogin\Tests;

use App\Filament\Pages\Modules;
use App\Models\Employee;
use App\Modules\MicrosoftLogin\Filament\Pages\MicrosoftLoginSettingsPage;
use App\Modules\MicrosoftLogin\Services\MicrosoftLoginSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Tests\Concerns\CreatesEmployees;
use Tests\TestCase;

class MicrosoftLoginTest extends TestCase
{
    use CreatesEmployees;
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    private const CLIENT = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdminPanel();
    }

    private function configure(bool $enabled = true): void
    {
        app(MicrosoftLoginSettings::class)->update([
            'enabled' => $enabled,
            'tenant_id' => self::TENANT,
            'client_id' => self::CLIENT,
            'client_secret' => 'the-secret',
        ]);
    }

    /**
     * Microsoft answers with this profile (Microsoft Graph /me) and an ID token of this tenant.
     *
     * @param  array<string, mixed>  $profile
     */
    private function microsoftReturns(array $profile, string $tenant = self::TENANT): void
    {
        $account = (new SocialiteUser)->setRaw($profile)->map(['id' => 'oid-1', 'email' => $profile['userPrincipalName'] ?? null]);

        $provider = Mockery::mock(MicrosoftProvider::class);
        $provider->shouldReceive('user')->andReturn($account);
        $provider->shouldReceive('getClaims')->andReturn((object) ['tid' => $tenant]);
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);
    }

    private function returnFromMicrosoft(array $query = ['code' => 'the-code', 'state' => 'the-state'])
    {
        return $this->get(route('microsoft_login.callback', $query));
    }

    // --- Settings

    public function test_the_secret_is_stored_encrypted_and_a_blank_secret_keeps_it(): void
    {
        $this->configure();
        $settings = app(MicrosoftLoginSettings::class);

        $stored = DB::table('settings')->where('key', 'microsoft_login.client_secret')->value('value');
        $this->assertNotSame('the-secret', $stored);
        $this->assertSame('the-secret', $settings->secret());

        $settings->update(['enabled' => true, 'tenant_id' => self::TENANT, 'client_id' => self::CLIENT, 'client_secret' => '']);
        $this->assertSame('the-secret', $settings->secret());
    }

    public function test_it_is_ready_only_when_switched_on_and_complete(): void
    {
        $settings = app(MicrosoftLoginSettings::class);
        $this->assertFalse($settings->isReady());

        $this->configure(enabled: false);
        $this->assertFalse($settings->isReady());

        $this->configure();
        $this->assertTrue($settings->isReady());

        $settings->update(['enabled' => true, 'tenant_id' => 'not-a-guid', 'client_id' => self::CLIENT]);
        $this->assertFalse($settings->isReady());
    }

    public function test_an_admin_saves_the_settings_and_the_ids_are_checked(): void
    {
        $this->loginAsAdmin();

        Livewire::test(MicrosoftLoginSettingsPage::class)
            ->fillForm(['enabled' => true, 'tenant_id' => 'contoso', 'client_id' => self::CLIENT, 'client_secret' => 'the-secret'])
            ->call('save')
            ->assertHasFormErrors(['tenant_id']);

        Livewire::test(MicrosoftLoginSettingsPage::class)
            ->assertFormSet(['redirect_uri' => route('microsoft_login.callback')])
            ->fillForm(['enabled' => true, 'tenant_id' => strtoupper(self::TENANT), 'client_id' => self::CLIENT, 'client_secret' => 'the-secret'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(app(MicrosoftLoginSettings::class)->isReady());
        $this->assertSame(self::TENANT, app(MicrosoftLoginSettings::class)->all()['tenant_id']);
    }

    public function test_an_employee_cannot_open_the_settings(): void
    {
        $this->actingAs($this->makeEmployee('Alice'));

        $this->get(MicrosoftLoginSettingsPage::getUrl())->assertForbidden();
    }

    public function test_the_modules_page_links_to_the_settings(): void
    {
        $this->loginAsAdmin();

        Livewire::test(Modules::class)->assertSee(MicrosoftLoginSettingsPage::getUrl());
    }

    // --- Sign-in page

    public function test_the_button_is_shown_only_once_it_is_set_up(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('Sign in with Microsoft');

        $this->configure();

        $this->get('/login')->assertOk()->assertSee('Sign in with Microsoft');
    }

    public function test_nothing_happens_before_it_is_set_up(): void
    {
        $this->configure(enabled: false);

        $this->get(route('microsoft_login.redirect'))->assertNotFound();
        $this->returnFromMicrosoft()->assertNotFound();
    }

    public function test_the_button_sends_to_the_microsoft_page_of_the_tenant(): void
    {
        $this->configure();

        $location = $this->get(route('microsoft_login.redirect'))->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(self::CLIENT, $query['client_id']);
        $this->assertSame(route('microsoft_login.callback'), $query['redirect_uri']);
        $this->assertSame('select_account', $query['prompt']);
        $this->assertNotEmpty($query['state']);
        $this->assertStringNotContainsString('the-secret', $location);
    }

    // --- Coming back from Microsoft

    public function test_an_employee_is_signed_in_by_their_microsoft_email_and_lands_on_the_portal(): void
    {
        $this->configure();
        $alice = $this->makeEmployee('Alice');
        $this->microsoftReturns(['mail' => 'ALICE@Example.com', 'userPrincipalName' => 'alice@contoso.onmicrosoft.com']);

        $this->returnFromMicrosoft()->assertRedirect(Filament::getPanel('portal')->getUrl());

        $this->assertAuthenticatedAs($alice);
    }

    public function test_the_user_principal_name_is_used_when_there_is_no_mail(): void
    {
        $this->configure();
        $boss = $this->makeEmployee('Boss', 'admin');
        $this->microsoftReturns(['mail' => null, 'userPrincipalName' => 'boss@example.com']);

        $this->returnFromMicrosoft()->assertRedirect(Filament::getPanel('admin')->getUrl());

        $this->assertAuthenticatedAs($boss);
    }

    public function test_the_page_wanted_before_signing_in_is_opened(): void
    {
        $this->configure();
        $this->makeEmployee('Alice');
        $this->microsoftReturns(['mail' => 'alice@example.com']);
        $wanted = Filament::getPanel('portal')->getUrl().'/my-claims';

        $this->withSession(['url.intended' => $wanted])->returnFromMicrosoft()->assertRedirect($wanted);
    }

    public function test_an_unknown_or_inactive_employee_is_refused(): void
    {
        $this->configure();
        $this->makeEmployee('Gone', attributes: ['is_active' => false]);

        $this->microsoftReturns(['mail' => 'stranger@example.com']);
        $this->returnFromMicrosoft()->assertRedirect('/login');
        $this->assertGuest();

        $this->microsoftReturns(['mail' => 'gone@example.com']);
        $this->returnFromMicrosoft()->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_an_account_of_another_tenant_is_refused(): void
    {
        $this->configure();
        $this->makeEmployee('Alice');
        $this->microsoftReturns(['mail' => 'alice@example.com'], tenant: '99999999-9999-9999-9999-999999999999');

        $this->returnFromMicrosoft()->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_a_cancelled_or_failed_sign_in_goes_back_to_the_sign_in_page(): void
    {
        $this->configure();

        $this->returnFromMicrosoft(['error' => 'access_denied', 'error_description' => 'The user cancelled'])->assertRedirect('/login');
        $this->assertGuest();

        $provider = Mockery::mock(MicrosoftProvider::class);
        $provider->shouldReceive('user')->andThrow(new RuntimeException('Invalid state'));
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);

        $this->returnFromMicrosoft()->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_two_employees_with_the_email_of_the_account_are_refused(): void
    {
        $this->configure();
        $this->makeEmployee('Alice');
        $this->makeEmployee('Twin', attributes: ['email' => 'twin@example.com']);
        // The mail matches one employee, the user principal name the other: no guess
        $this->microsoftReturns(['mail' => 'alice@example.com', 'userPrincipalName' => 'twin@example.com']);

        $this->returnFromMicrosoft()->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(2, Employee::count());
    }
}
