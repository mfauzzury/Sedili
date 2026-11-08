<?php

namespace App\Models\Concerns;

use Carbon\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Records who created, changed or deleted a record (System > Activity log).
 *
 * Only the fields that really changed are stored, and never secrets. A model can hide more fields
 * with a "$auditExcept" property.
 */
trait Audited
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logExcept(array_merge(
                ['password', 'remember_token', 'updated_at', 'created_at', 'deleted_at'],
                $this->auditExcept ?? [],
            ))
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName(class_basename($this));
    }

    /**
     * A "date" is logged as the date ("2024-03-05"), not as the timestamp with a time zone that
     * the package makes of it.
     */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        $dates = collect($this->getCasts())
            ->filter(fn (string $cast) => $cast === 'date' || str_starts_with($cast, 'date:'))
            ->keys();
        if ($dates->isEmpty()) {
            return;
        }

        $properties = $activity->properties->toArray();
        foreach (['attributes', 'old'] as $group) {
            foreach ($dates as $field) {
                if (isset($properties[$group][$field]) && is_string($properties[$group][$field])) {
                    $properties[$group][$field] = Carbon::parse($properties[$group][$field])
                        ->setTimezone(config('app.timezone'))
                        ->toDateString();
                }
            }
        }
        $activity->properties = collect($properties);
    }
}
