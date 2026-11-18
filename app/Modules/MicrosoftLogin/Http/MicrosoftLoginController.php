<?php

namespace App\Modules\MicrosoftLogin\Http;

use App\Filament\Auth\Landing;
use App\Models\Employee;
use App\Modules\MicrosoftLogin\Services\MicrosoftLoginSettings;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use LogicException;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Sign in with Microsoft (OpenID Connect, authorization code flow, through Socialite).
 *
 * Microsoft checks the password and the MFA. Here, the answer is accepted only from the configured
 * tenant, and only for an employee who already exists, is active and has the same email as the
 * Microsoft account: nobody is created, and the roles stay managed in the application.
 */
class MicrosoftLoginController
{
    public function __construct(private readonly MicrosoftLoginSettings $settings) {}

    public function redirect(): SymfonyRedirect
    {
        abort_unless($this->settings->isReady(), 404);
        $this->settings->apply();

        return $this->provider()
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    /**
     * The "microsoft" Socialite driver, registered by MicrosoftLoginModule::boot().
     */
    private function provider(): MicrosoftProvider
    {
        $provider = Socialite::driver('microsoft');
        if (! $provider instanceof MicrosoftProvider) {
            throw new LogicException('The "microsoft" Socialite driver is not the Microsoft provider.');
        }

        return $provider;
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($this->settings->isReady(), 404);
        $this->settings->apply();

        // The employee cancelled, or Microsoft refused (consent, conditional access...)
        if ($request->filled('error')) {
            return $this->refuse('Microsoft sign-in was cancelled or refused.', $request->string('error_description')->limit(300)->toString());
        }

        try {
            $provider = $this->provider();
            $account = $provider->user();
            // The ID token was checked by the provider (signature, issuer, audience, expiry): its tenant must be ours
            $tenant = (string) ($provider->getClaims()->tid ?? '');
        } catch (Throwable $e) {
            report($e);

            return $this->refuse('Microsoft sign-in failed. Please try again.');
        }

        if (strcasecmp($tenant, (string) $this->settings->all()['tenant_id']) !== 0) {
            return $this->refuse('This Microsoft account does not belong to the organisation.');
        }

        $employee = $this->findEmployee($account->getRaw());
        if ($employee === null || ! $this->mayEnter($employee)) {
            return $this->refuse(
                'No active employee account matches this Microsoft account.',
                'Ask HR to check that your email in the system is the same as your Microsoft email.'
            );
        }

        $guard = Filament::getPanel('admin')->auth();
        $guard->login($employee);
        $request->session()->regenerate();

        $intended = Landing::allowedIntended($employee, $request->session()->pull('url.intended'));

        return redirect()->to($intended ?? Landing::homeFor($employee));
    }

    /**
     * The employee whose email is the mail or the user principal name of the Microsoft account
     * (they differ for some accounts), ignoring the case.
     *
     * @param  array<string, mixed>  $raw  the Microsoft Graph profile
     */
    private function findEmployee(array $raw): ?Employee
    {
        $emails = collect([$raw['mail'] ?? null, $raw['userPrincipalName'] ?? null])
            ->filter(fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->unique()
            ->values();

        if ($emails->isEmpty()) {
            return null;
        }

        $matches = Employee::query()
            ->whereIn(DB::raw('lower(email)'), $emails->all())
            ->get();

        // Two employees for one Microsoft account: do not guess
        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Same rule as the password sign-in (see App\Filament\Auth\Login): active, and allowed into at least one panel.
     */
    private function mayEnter(Employee $employee): bool
    {
        return $employee->canAccessPanel(Filament::getPanel('admin'))
            || $employee->canAccessPanel(Filament::getPanel('portal'));
    }

    private function refuse(string $title, ?string $body = null): RedirectResponse
    {
        Notification::make()->title($title)->body($body)->danger()->persistent()->send();

        return redirect()->to(Filament::getPanel('admin')->getLoginUrl());
    }
}
