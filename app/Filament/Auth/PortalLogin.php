<?php

namespace App\Filament\Auth;

use Filament\Facades\Filament;
use Filament\Support\Components\Attributes\Unexposed;

/**
 * The portal has no sign-in page of its own: /portal/login (and the redirect of the portal pages for a
 * visitor who is not signed in) lead to the single login page. The page the visitor wanted is kept in the
 * session, so they get there after signing in.
 */
class PortalLogin extends Login
{
    #[Unexposed]
    public function mount(): void
    {
        if (Filament::auth()->check()) {
            parent::mount();

            return;
        }

        redirect()->to(Filament::getPanel('admin')->getLoginUrl());
    }
}
