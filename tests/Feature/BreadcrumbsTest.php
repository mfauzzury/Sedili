<?php

namespace Tests\Feature;

use App\Filament\Pages\CompanySettings;
use App\Filament\Pages\OrgChart;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesEmployees;
use Tests\TestCase;

class BreadcrumbsTest extends TestCase
{
    use CreatesEmployees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdminPanel();
    }

    public function test_both_panels_show_breadcrumbs_with_the_menu_group(): void
    {
        foreach (['admin', 'portal'] as $panel) {
            $this->assertTrue(Filament::getPanel($panel)->hasBreadcrumbs());
            $this->assertTrue(Filament::getPanel($panel)->hasNavigationHierarchyInBreadcrumbs());
        }
    }

    public function test_the_pages_that_are_not_resources_have_a_breadcrumb_too(): void
    {
        $this->loginAsAdmin();

        $this->assertSame(['Organization', 'Org Chart'], array_values(Livewire::test(OrgChart::class)->instance()->getBreadcrumbs()));
        $this->assertSame(['System', 'Company'], array_values(Livewire::test(CompanySettings::class)->instance()->getBreadcrumbs()));
    }
}
