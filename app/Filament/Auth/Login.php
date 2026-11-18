<?php

namespace App\Filament\Auth;

use App\Models\Employee;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The one sign-in page of the application, for admins and employees alike. Whoever may enter at least
 * one panel can sign in here; where they land depends on their role (see Landing and LoginResponse).
 * The portal has no login page of its own: /portal/login sends here (see PortalLogin).
 */
class Login extends BaseLogin
{
    /** Split layout: the form and a quiet animated panel (resources/views/filament/layouts/auth.blade.php) */
    protected static string $layout = 'filament.layouts.auth';

    #[Unexposed]
    public function mount(): void
    {
        if (Filament::auth()->check()) {
            $user = Filament::auth()->user();
            redirect()->to(Landing::homeFor($user instanceof Employee ? $user : null));
        }

        $this->form->fill();
    }

    #[Unexposed]
    public function getHeading(): string|Htmlable|null
    {
        // The multi-factor step keeps its own heading
        return filled($this->userUndertakingMultiFactorAuthentication) ? parent::getHeading() : 'Welcome back';
    }

    #[Unexposed]
    public function getSubheading(): string|Htmlable|null
    {
        return filled($this->userUndertakingMultiFactorAuthentication) ? parent::getSubheading() : 'Sign in with your email and password.';
    }

    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        if (! ($user instanceof FilamentUser)) {
            return true;
        }

        // Each panel still decides for itself afterwards: an employee is not let into the admin panel
        return $user->canAccessPanel(Filament::getPanel('admin')) || $user->canAccessPanel(Filament::getPanel('portal'));
    }
}
