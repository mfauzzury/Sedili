<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * SMTP server used to send the emails of the application, chosen in System > Email and stored
 * in the settings table. While it is enabled it replaces the MAIL_* values of the .env file;
 * otherwise the .env values are used. The password is stored encrypted.
 */
class MailSettings
{
    /** Settings keys, all stored as "mail.{field}" */
    public const FIELDS = ['enabled', 'host', 'port', 'scheme', 'username', 'password', 'from_address', 'from_name'];

    public function __construct(
        private readonly SettingsStore $store,
        private readonly CompanyProfile $company,
    ) {
    }

    /**
     * The saved values, without the password (see hasPassword()).
     *
     * @return array<string, string|bool|null>
     */
    public function all(): array
    {
        $values = $this->store->group('mail', self::FIELDS);
        unset($values['password']);
        $values['enabled'] = $values['enabled'] === '1';
        return $values;
    }

    public function isEnabled(): bool
    {
        return $this->all()['enabled'];
    }

    public function hasPassword(): bool
    {
        return $this->password() !== null;
    }

    public function password(): ?string
    {
        $stored = $this->store->group('mail', ['password'])['password'];
        if ($stored === null) {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            // Saved with another APP_KEY: the password has to be entered again
            return null;
        }
    }

    /**
     * Save the settings. A blank password keeps the saved one.
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::FIELDS));

        $values['enabled'] = !empty($values['enabled']) ? '1' : '0';
        if (blank($values['password'] ?? null)) {
            unset($values['password']);
        } else {
            $values['password'] = Crypt::encryptString($values['password']);
        }

        $this->store->save('mail', $values);
    }

    /**
     * Use the saved SMTP server for the emails of the current request (nothing changes while
     * the settings are disabled).
     */
    public function apply(): void
    {
        if ($this->isEnabled()) {
            $this->applyValues($this->all() + ['password' => $this->password()]);
        }
    }

    /**
     * Use the given SMTP server for the emails of the current request (also used to test settings
     * that are not saved yet).
     *
     * @param array<string, mixed> $values
     */
    public function applyValues(array $values): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $values['host'] ?? null,
            'mail.mailers.smtp.port' => (int) ($values['port'] ?? 587),
            'mail.mailers.smtp.scheme' => $values['scheme'] ?? 'smtp',
            'mail.mailers.smtp.username' => $values['username'] ?? null,
            'mail.mailers.smtp.password' => $values['password'] ?? null,
            'mail.mailers.smtp.url' => null,
        ]);
        if (filled($values['from_address'] ?? null)) {
            config([
                'mail.from.address' => $values['from_address'],
                'mail.from.name' => filled($values['from_name'] ?? null)
                    ? $values['from_name']
                    : ($this->company->name() ?? config('mail.from.name')),
            ]);
        }
    }
}
