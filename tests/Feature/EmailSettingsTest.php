<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailSettings;
use App\Models\Employee;
use App\Modules\ModuleManager;
use App\Services\CompanyProfile;
use App\Services\MailSettings;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class EmailSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    private function actingAsEmployee(string $role): void
    {
        $employee = new Employee();
        $employee->forceFill([
            'first_name' => 'Test',
            'last_name' => ucfirst($role),
            'email' => "$role@example.com",
            'password' => 'password',
            'employment_type' => 'Permanent',
        ])->save();
        $employee->assignRole($role);
        $this->actingAs($employee->fresh());
    }

    /** @return array<string, mixed> */
    private function validForm(array $override = []): array
    {
        return [
            'enabled' => true,
            'host' => 'smtp.example.com',
            'port' => 465,
            'scheme' => 'smtps',
            'username' => 'mailer',
            'password' => 's3cret-password',
            'from_address' => 'hr@example.com',
            'from_name' => 'Sedili HR',
            ...$override,
        ];
    }

    public function test_nothing_changes_while_the_settings_are_disabled(): void
    {
        $default = config('mail.default');

        app(MailSettings::class)->apply();

        $this->assertSame($default, config('mail.default'));
    }

    public function test_an_admin_saves_the_settings_and_the_password_is_stored_encrypted(): void
    {
        $this->actingAsEmployee('admin');

        Livewire::test(EmailSettings::class)
            ->fillForm($this->validForm())
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = DB::table('settings')->where('key', 'mail.password')->value('value');
        $this->assertNotSame('s3cret-password', $stored);
        $this->assertSame('s3cret-password', Crypt::decryptString($stored));

        // The saved server is used for the emails of the application
        app(MailSettings::class)->apply();
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('mailer', config('mail.mailers.smtp.username'));
        $this->assertSame('s3cret-password', config('mail.mailers.smtp.password'));
        $this->assertSame('hr@example.com', config('mail.from.address'));
        $this->assertSame('Sedili HR', config('mail.from.name'));
    }

    public function test_the_password_is_never_sent_back_to_the_form_and_a_blank_one_keeps_the_saved_one(): void
    {
        $this->actingAsEmployee('admin');
        Livewire::test(EmailSettings::class)->fillForm($this->validForm())->call('save');

        Livewire::test(EmailSettings::class)
            ->assertSet('data.password', null)
            ->assertSet('data.host', 'smtp.example.com')
            ->fillForm(['password' => '', 'port' => 587])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('s3cret-password', app(MailSettings::class)->password());
        $this->assertSame('587', DB::table('settings')->where('key', 'mail.port')->value('value'));
    }

    public function test_the_server_is_required_when_the_settings_are_enabled(): void
    {
        $this->actingAsEmployee('admin');

        Livewire::test(EmailSettings::class)
            ->fillForm($this->validForm(['host' => '', 'from_address' => '']))
            ->call('save')
            ->assertHasFormErrors(['host' => 'required', 'from_address' => 'required']);

        // Disabled settings can stay incomplete
        Livewire::test(EmailSettings::class)
            ->fillForm($this->validForm(['enabled' => false, 'host' => '', 'from_address' => '']))
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_a_failing_test_email_shows_the_error_instead_of_crashing(): void
    {
        $this->actingAsEmployee('admin');

        // Nothing listens on port 1
        Livewire::test(EmailSettings::class)
            ->fillForm($this->validForm(['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'smtp']))
            ->call('sendTest')
            ->assertNotified('The test email could not be sent');
    }

    public function test_the_preview_shows_the_sample_emails_with_the_branding_of_the_company(): void
    {
        $this->actingAsEmployee('admin');
        app(CompanyProfile::class)->update(['name' => 'Sedili Sdn Bhd', 'logo' => 'branding/logo.png']);

        config(['app.name' => 'Laravel']);
        $page = Livewire::test(EmailSettings::class);

        $this->assertSame(['Leave requested', 'Leave requested (email to the supervisor)', 'Leave approved', 'Leave rejected'], $page->instance()->getPreviewOptions());

        // The first one is shown until another one is chosen
        $html = $page->instance()->getPreviewHtml();
        $this->assertStringContainsString('Test Admin requested leave', $html);
        $this->assertStringContainsString('Family trip', $html);
        $this->assertStringContainsString('/storage/branding/logo.png', $html);
        $this->assertStringContainsString(date('Y') . ' Sedili Sdn Bhd', html_entity_decode($html));
        // Header, sign-off and footer use the company, never the app name
        $this->assertStringNotContainsString('Laravel', $html);
        $this->assertMatchesRegularExpression('/Regards,\s*<br\s*\/?>\s*Sedili Sdn Bhd/', $html);

        $page->set('previewEmail', 'Leave rejected');
        $html = $page->instance()->getPreviewHtml();
        $this->assertStringContainsString('request for', $html);
        $this->assertStringContainsString('was rejected', $html);
        $this->assertStringContainsString('The team is short-staffed that week.', $html);

        // The preview itself is in the page, inside a sandboxed frame
        $page->assertSeeHtml('sandbox');
    }

    public function test_the_header_shows_the_name_when_there_is_no_logo(): void
    {
        $this->actingAsEmployee('admin');
        app(CompanyProfile::class)->update(['name' => 'Sedili Sdn Bhd']);

        $html = Livewire::test(EmailSettings::class)->instance()->getPreviewHtml();

        $this->assertStringContainsString('Sedili Sdn Bhd', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_there_is_no_preview_when_the_module_with_the_emails_is_disabled(): void
    {
        $this->actingAsEmployee('admin');
        app(ModuleManager::class)->setEnabled('leave', false);

        $page = Livewire::test(EmailSettings::class);

        $this->assertSame([], $page->instance()->getPreviewOptions());
        $this->assertNull($page->instance()->getPreviewHtml());
        $page->assertDontSee('Preview');
    }

    public function test_an_employee_cannot_open_the_page(): void
    {
        $this->actingAsEmployee('employee');

        $this->get(EmailSettings::getUrl())->assertForbidden();
    }
}
