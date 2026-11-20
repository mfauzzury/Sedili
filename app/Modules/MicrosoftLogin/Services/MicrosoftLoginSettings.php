<?php

namespace App\Modules\MicrosoftLogin\Services;

use App\Services\SettingsStore;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The Microsoft Entra ID app registration used to sign in with Microsoft, set in System > Microsoft
 * sign-in and stored in the settings table ("microsoft_login.{field}"). The client secret is stored
 * encrypted and never shown again.
 */
class MicrosoftLoginSettings
{
    public const FIELDS = ['enabled', 'tenant_id', 'client_id', 'client_secret'];

    /** A directory (tenant) or application (client) ID: a GUID */
    public const GUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private readonly SettingsStore $store) {}

    /**
     * The saved values, without the secret (see hasSecret()).
     *
     * @return array{enabled: bool, tenant_id: string|null, client_id: string|null}
     */
    public function all(): array
    {
        $values = $this->store->group('microsoft_login', ['enabled', 'tenant_id', 'client_id']);

        return [
            'enabled' => $values['enabled'] === '1',
            'tenant_id' => $values['tenant_id'],
            'client_id' => $values['client_id'],
        ];
    }

    public function hasSecret(): bool
    {
        return $this->secret() !== null;
    }

    public function secret(): ?string
    {
        $stored = $this->store->group('microsoft_login', ['client_secret'])['client_secret'];
        if ($stored === null) {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            // Saved with another APP_KEY: the secret has to be entered again
            return null;
        }
    }

    /**
     * Switched on and complete: the button is shown on the sign-in page.
     */
    public function isReady(): bool
    {
        $values = $this->all();

        return $values['enabled']
            && self::isGuid($values['tenant_id'])
            && self::isGuid($values['client_id'])
            && $this->hasSecret();
    }

    public static function isGuid(?string $value): bool
    {
        return $value !== null && preg_match(self::GUID_PATTERN, $value) === 1;
    }

    /**
     * Save the settings. A blank secret keeps the saved one.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::FIELDS));

        $values['enabled'] = ! empty($values['enabled']) ? '1' : '0';
        foreach (['tenant_id', 'client_id'] as $field) {
            if (isset($values[$field])) {
                $values[$field] = strtolower(trim((string) $values[$field]));
            }
        }
        if (blank($values['client_secret'] ?? null)) {
            unset($values['client_secret']);
        } else {
            $values['client_secret'] = Crypt::encryptString((string) $values['client_secret']);
        }

        $this->store->save('microsoft_login', $values);
    }

    /**
     * The address Microsoft sends the employee back to: it must be added to the app registration
     * (Authentication > Web > Redirect URIs) exactly as it is.
     */
    public function redirectUri(): string
    {
        return route('microsoft_login.callback');
    }

    /**
     * Configure the Socialite "microsoft" driver for the current request. Only for one tenant: the
     * accounts of other organisations and personal Microsoft accounts are refused by Microsoft.
     */
    public function apply(): void
    {
        $values = $this->all();

        config(['services.microsoft' => [
            'client_id' => $values['client_id'],
            'client_secret' => $this->secret(),
            'redirect' => $this->redirectUri(),
            'tenant' => $values['tenant_id'],
        ]]);
    }
}
