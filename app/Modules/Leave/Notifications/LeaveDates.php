<?php

namespace App\Modules\Leave\Notifications;

use App\Modules\Leave\Models\Leave;

/**
 * Dates of a leave as text for the emails ("12 Mar 2026" or "12 Mar 2026 to 14 Mar 2026").
 */
class LeaveDates
{
    public static function format(Leave $leave): string
    {
        $start = $leave->start_date->format('j M Y');
        $end = $leave->end_date->format('j M Y');

        return $start === $end ? $start : "{$start} to {$end}";
    }
}
