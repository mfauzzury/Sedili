<?php

namespace App\Providers\Filament;

use Filament\Pages\Dashboard;
use App\Modules\ModuleManager;
use App\Filament\Employee\Widgets\MyTodos;
use App\Services\CompanyProfile;

use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class EmployeePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $modules = app(ModuleManager::class);

        return $panel
            ->id('portal')
            ->path('portal')
            ->colors([
                'primary' => Color::Cyan,
            ])
            ->databaseNotifications()
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->sidebarWidth('16rem')
            ->spa()
            ->login(\App\Filament\Auth\PortalLogin::class)
            ->passwordReset()
            ->profile()

            ->brandName(fn() => app(CompanyProfile::class)->name() ?? 'Portal')
            ->brandLogo(fn() => app(CompanyProfile::class)->logoUrl())
            ->brandLogoHeight('1.5rem')
            // Breadcrumbs on every page, with the menu group in front (Organization > Org Chart)
            ->breadcrumbs(true, hasNavigationHierarchy: true)
            ->favicon(fn() => app(CompanyProfile::class)->faviconUrl())


            ->discoverResources(in: app_path('Filament/Employee/Resources'), for: 'App\\Filament\\Employee\\Resources')
            ->discoverPages(in: app_path('Filament/Employee/Pages'), for: 'App\\Filament\\Employee\\Pages')
            ->pages([
                Dashboard::class,
                ...$modules->filament('portal', 'pages'),
            ])
            ->resources($modules->filament('portal', 'resources'))
            // The dashboard shows what the employee has to do (collected from the modules, see Module::todos()).
            // The widgets of the modules are not shown on it, but they stay registered with Livewire
            // (a widget that is embedded in a page still needs that)
            ->widgets([MyTodos::class])
            ->livewireComponents([
                ...$modules->filament('portal', 'livewire'),
                ...$modules->filament('portal', 'widgets'),
            ])

            ->navigationGroups([
                'Leave',
                'Work space',
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
