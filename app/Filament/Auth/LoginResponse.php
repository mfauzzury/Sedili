<?php

namespace App\Filament\Auth;

use App\Models\Employee;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as Responsable;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * After signing in: back to the page the person wanted when they may open it, otherwise to the home
 * of their panel (admins: admin panel, employees: portal).
 */
class LoginResponse implements Responsable
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $user = Filament::auth()->user();
        $employee = $user instanceof Employee ? $user : null;
        $intended = Landing::allowedIntended($employee, session()->pull('url.intended'));

        return redirect()->to($intended ?? Landing::homeFor($employee));
    }
}
