<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Name, logo and contact details of the company, stored in the settings table.
 * Used for the branding of both panels and available for documents (payslips, letters...).
 */
class CompanyProfile
{
    public const DISK = 'public';
    public const DIRECTORY = 'branding';

    /** Settings keys, all stored as "company.{field}" */
    public const FIELDS = ['name', 'registration_no', 'address', 'phone', 'email', 'website', 'logo'];

    public function __construct(private readonly SettingsStore $store)
    {
    }

    /**
     * @return array<string, string|null>
     */
    public function all(): array
    {
        return $this->store->group('company', self::FIELDS);
    }

    public function get(string $field): ?string
    {
        return $this->all()[$field] ?? null;
    }

    public function name(): ?string
    {
        return $this->get('name');
    }

    public function logoUrl(): ?string
    {
        $logo = $this->get('logo');
        // Built from the host of the current request (through the public/storage link), not from
        // APP_URL, so that the logo also loads when the app is opened on another host or port
        return $logo ? asset('storage/' . $logo) : null;
    }

    /**
     * Save the given fields (the others are left untouched). The previous logo file is deleted
     * when it is replaced or removed.
     *
     * @param array<string, string|null> $values
     */
    public function update(array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::FIELDS));
        $previousLogo = $this->get('logo');

        $this->store->save('company', $values);

        if ($previousLogo && array_key_exists('logo', $values) && $values['logo'] !== $previousLogo) {
            Storage::disk(self::DISK)->delete($previousLogo);
        }
    }
}
