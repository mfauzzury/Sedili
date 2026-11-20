<?php

namespace App\Modules\MicrosoftLogin;

use App\Filament\Auth\Login;
use App\Modules\MicrosoftLogin\Filament\Pages\MicrosoftLoginSettingsPage;
use App\Modules\MicrosoftLogin\Http\MicrosoftLoginController;
use App\Modules\MicrosoftLogin\Services\MicrosoftLoginSettings;
use App\Modules\Module;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

class MicrosoftLoginModule extends Module
{
    public function key(): string
    {
        return 'microsoft_login';
    }

    public function label(): string
    {
        return 'Microsoft sign-in';
    }

    public function description(): string
    {
        return 'Employees sign in with their Microsoft work account (Microsoft 365 / Entra ID). Set up the app registration in System > Microsoft sign-in.';
    }

    public function filament(string $panelId): array
    {
        return $panelId === 'admin' ? ['pages' => [MicrosoftLoginSettingsPage::class]] : [];
    }

    public function settingsUrl(): ?string
    {
        return MicrosoftLoginSettingsPage::getUrl(panel: 'admin');
    }

    public function boot(): void
    {
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('microsoft', MicrosoftProvider::class);
        });

        // Throttled: each call reaches Microsoft. No "auth" middleware: these are for guests.
        Route::middleware(['web', 'throttle:20,1'])
            ->prefix('auth/microsoft')
            ->group(function () {
                Route::get('redirect', [MicrosoftLoginController::class, 'redirect'])->name('microsoft_login.redirect');
                Route::get('callback', [MicrosoftLoginController::class, 'callback'])->name('microsoft_login.callback');
            });

        // The button under the password form, only once the app registration is set up
        FilamentView::registerRenderHook(
            PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
            fn (): string => app(MicrosoftLoginSettings::class)->isReady() ? view('microsoft_login::login-button')->render() : '',
            scopes: Login::class,
        );
    }
}
