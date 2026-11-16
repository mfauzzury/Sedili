<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Name, logo, favicon and contact details of the company, stored in the settings table.
 * Used for the branding of both panels and available for documents (payslips, letters...).
 */
class CompanyProfile
{
    public const DISK = 'public';
    public const DIRECTORY = 'branding';

    /** Settings keys, all stored as "company.{field}" */
    public const FIELDS = ['name', 'registration_no', 'address', 'phone', 'email', 'website', 'logo', 'favicon'];

    /** Fields that hold an uploaded file (path on DISK) */
    public const FILE_FIELDS = ['logo', 'favicon'];

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
        return $this->fileUrl('logo');
    }

    public function faviconUrl(): ?string
    {
        return $this->fileUrl('favicon');
    }

    private function fileUrl(string $field): ?string
    {
        $path = $this->get($field);
        // Built from the host of the current request (through the public/storage link), not from
        // APP_URL, so that the file also loads when the app is opened on another host or port
        return $path ? asset('storage/' . $path) : null;
    }

    /**
     * Save the given fields (the others are left untouched). The previous logo or favicon file is
     * deleted when it is replaced or removed.
     *
     * @param array<string, string|null> $values
     */
    public function update(array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::FIELDS));
        $previous = $this->all();

        $this->store->save('company', $values);

        foreach (self::FILE_FIELDS as $field) {
            $old = $previous[$field] ?? null;
            if ($old && array_key_exists($field, $values) && $values[$field] !== $old) {
                Storage::disk(self::DISK)->delete($old);
            }
        }
    }
}
