<?php

namespace Tests\Feature;

use App\Filament\Pages\CompanySettings;
use App\Models\Employee;
use App\Services\CompanyProfile;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CompanySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(CompanyProfile::DISK);
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

    public function test_the_panels_keep_their_default_name_without_settings(): void
    {
        $this->assertSame('Admin Panel', Filament::getPanel('admin')->getBrandName());
        $this->assertSame('Portal', Filament::getPanel('portal')->getBrandName());
        $this->assertNull(Filament::getPanel('admin')->getBrandLogo());
    }

    public function test_an_admin_can_save_the_company_details_and_the_logo(): void
    {
        $this->actingAsEmployee('admin');

        Livewire::test(CompanySettings::class)
            ->fillForm([
                'name' => 'Sedili Sdn Bhd',
                'registration_no' => '202401000001',
                'email' => 'hello@sedili.test',
                'address' => '1 Jalan Satu, Kuala Lumpur',
                'logo' => UploadedFile::fake()->image('logo.png', 400, 100),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $profile = app(CompanyProfile::class);
        $this->assertSame('Sedili Sdn Bhd', $profile->name());
        $this->assertSame('202401000001', $profile->get('registration_no'));
        $this->assertSame('1 Jalan Satu, Kuala Lumpur', $profile->get('address'));
        Storage::disk(CompanyProfile::DISK)->assertExists($profile->get('logo'));

        $this->assertSame('Sedili Sdn Bhd', Filament::getPanel('admin')->getBrandName());
        $this->assertSame('Sedili Sdn Bhd', Filament::getPanel('portal')->getBrandName());
        $this->assertSame($profile->logoUrl(), Filament::getPanel('portal')->getBrandLogo());
        $this->assertStringEndsWith('/storage/' . $profile->get('logo'), $profile->logoUrl());

        // The logo is loaded from the host the page is opened on, whatever APP_URL says
        $this->get('http://sedili.test:8000/')->assertOk()->assertSee('http://sedili.test:8000/storage/' . $profile->get('logo'), false);
    }

    public function test_the_files_of_the_public_disk_have_a_url_that_works_on_any_host(): void
    {
        // The upload field gives this URL to the browser to load the saved logo and read its size. It must
        // not contain APP_URL: opened on another host or port, the browser would never get the file and
        // the field would stay on "Waiting for size" (the fake disk of these tests does not use this config).
        $disk = Storage::build(config('filesystems.disks.' . CompanyProfile::DISK));

        $this->assertSame('/storage/branding/logo.png', $disk->url('branding/logo.png'));
    }

    public function test_replacing_the_logo_deletes_the_previous_file(): void
    {
        $this->actingAsEmployee('admin');
        Livewire::test(CompanySettings::class)
            ->fillForm(['logo' => UploadedFile::fake()->image('one.png')])
            ->call('save');
        $first = app(CompanyProfile::class)->get('logo');

        // In the browser, the user first removes the current logo, then picks the new one
        Livewire::test(CompanySettings::class)
            ->set('data.logo', [])
            ->fillForm(['logo' => UploadedFile::fake()->image('two.png')])
            ->call('save');
        $second = app(CompanyProfile::class)->get('logo');

        $this->assertNotSame($first, $second);
        Storage::disk(CompanyProfile::DISK)->assertMissing($first);
        Storage::disk(CompanyProfile::DISK)->assertExists($second);
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $this->actingAsEmployee('admin');

        Livewire::test(CompanySettings::class)
            ->fillForm(['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])
            ->call('save')
            ->assertHasFormErrors(['logo']);

        $this->assertNull(app(CompanyProfile::class)->get('logo'));
    }

    public function test_an_employee_cannot_open_the_page(): void
    {
        $this->actingAsEmployee('employee');

        $this->get(CompanySettings::getUrl())->assertForbidden();
    }
}
