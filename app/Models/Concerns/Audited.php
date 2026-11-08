<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\LogOptions;
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
}
