<?php

namespace App\Filament\Auth;

use App\Models\Employee;
use Filament\Facades\Filament;

/**
 * Where somebody goes after signing in: admins to the admin panel, everybody else to the employee portal.
 * Both panels share one login page (see Login).
 */
final class Landing
{
    public static function homeFor(?Employee $employee): string
    {
        return Filament::getPanel($employee?->hasRole('admin') ? 'admin' : 'portal')->getUrl();
    }

    /**
     * The page the person wanted before they were asked to sign in, if they may open it: an admin may open
     * anything, an employee only the portal (the admin panel would answer 403).
     */
    public static function allowedIntended(?Employee $employee, ?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }
        if ($employee?->hasRole('admin')) {
            return $url;
        }

        $portal = trim(Filament::getPanel('portal')->getPath(), '/');
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path === $portal || str_starts_with($path, $portal . '/') ? $url : null;
    }
}
