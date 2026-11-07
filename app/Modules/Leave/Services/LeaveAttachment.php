<?php

namespace App\Modules\Leave\Services;

use App\Models\Employee;
use App\Modules\Leave\Models\Leave;
use Closure;
use Filament\Forms\Components\FileUpload;

/**
 * The document (medical certificate, ...) attached to a leave request.
 *
 * Documents are private: they are stored on the "local" disk (never reachable from the web
 * server) and only served by the "leave.attachment" route, to the employee who made the
 * request, to their supervisor and to the admins.
 */
class LeaveAttachment
{
    public const DISK = 'local';
    public const DIRECTORY = 'leave-attachments';

    /** What FileUpload stores: the directory and a generated name, nothing else */
    public const PATH_PATTERN = '#^leave-attachments/[A-Za-z0-9_.-]+$#';

    public static function url(Leave $leave): ?string
    {
        return filled($leave->attachment) ? route('leave.attachment', $leave) : null;
    }

    public static function isValidPath(string $path): bool
    {
        return preg_match(self::PATH_PATTERN, $path) === 1 && !str_contains($path, '..');
    }

    public static function canView(Employee $user, Leave $leave): bool
    {
        return (int) $leave->employee_id === (int) $user->id
            || $user->hasRole('admin')
            || Employee::query()->reportingTo($user)->whereKey($leave->employee_id)->exists();
    }

    /**
     * Upload field of the leave forms.
     *
     * @param Closure $required receives the form state (Get) and tells if the document is required
     */
    public static function field(Closure $required): FileUpload
    {
        return FileUpload::make('attachment')
            ->label('Evidence')
            ->disk(self::DISK)
            ->directory(self::DIRECTORY)
            ->visibility('private')
            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
            ->maxSize(5120)
            ->required($required)
            ->helperText(fn(FileUpload $component) => $component->isRequired()
                ? 'Required for this leave type, for example a medical certificate. PDF, JPG or PNG, 5 MB at most.'
                : 'Optional. PDF, JPG or PNG, 5 MB at most.');
    }
}
