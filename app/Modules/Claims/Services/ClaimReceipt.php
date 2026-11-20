<?php

namespace App\Modules\Claims\Services;

use App\Models\Employee;
use App\Modules\Claims\Models\ClaimItem;
use Closure;
use Filament\Forms\Components\FileUpload;

/**
 * The receipt attached to a claim item.
 *
 * Receipts are private: they are stored on the "local" disk (never reachable from the web
 * server) and only served by the "claims.receipt" route, to the employee who made the claim,
 * to their supervisor and to the admins.
 */
class ClaimReceipt
{
    public const DISK = 'local';

    public const DIRECTORY = 'claim-receipts';

    /** What FileUpload stores: the directory and a generated name, nothing else */
    public const PATH_PATTERN = '#^claim-receipts/[A-Za-z0-9_.-]+$#';

    public static function url(ClaimItem $item): ?string
    {
        return filled($item->receipt) ? route('claims.receipt', $item) : null;
    }

    public static function isValidPath(string $path): bool
    {
        return preg_match(self::PATH_PATTERN, $path) === 1 && ! str_contains($path, '..');
    }

    public static function canView(Employee $user, ClaimItem $item): bool
    {
        $owner = (int) $item->claim()->value('employee_id');

        return $owner === (int) $user->id
            || $user->hasRole('admin')
            || Employee::query()->reportingTo($user)->whereKey($owner)->exists();
    }

    /**
     * Upload field of the claim form.
     *
     * @param  Closure  $required  receives the form state (Get) and tells if the receipt is required
     */
    public static function field(Closure $required): FileUpload
    {
        return FileUpload::make('receipt')
            ->label('Receipt')
            ->disk(self::DISK)
            ->directory(self::DIRECTORY)
            ->visibility('private')
            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
            ->maxSize(5120)
            ->required($required)
            ->helperText(fn (FileUpload $component) => $component->isRequired()
                ? 'Required for this type. PDF, JPG or PNG, 5 MB at most.'
                : 'Optional. PDF, JPG or PNG, 5 MB at most.');
    }
}
