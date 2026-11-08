<?php

namespace App\Observers;

use App\Models\Department;
use App\Models\Employee;
use Filament\Notifications\Notification;

class DepartmentObserver
{
    /**
     * Handle the Department "created" event.
     */
    public function created(Department $department): void
    {
        //
        $this->sendManagerNotification($department);
    }

    /**
     * Handle the Department "updated" event.
     */
    public function updated(Department $department): void
    {
        // Only a new manager is told, not every edit of the name or the description
        if ($department->wasChanged('manager_id')) {
            $this->sendManagerNotification($department);
        }
    }

    private function sendManagerNotification(Department $department): void
    {
        if (blank($department->manager_id)) {
            return;
        }

        // Asked to the database: the "manager" relation may still hold the previous manager
        $manager = Employee::find($department->manager_id);
        if ($manager === null) {
            return;
        }

        Notification::make()
            ->title('You have been assigned a department')
            ->body($department->name)
            ->info()
            ->sendToDatabase($manager);
    }

    /**
     * Handle the Department "deleted" event.
     */
    public function deleted(Department $department): void
    {
        //
    }

    /**
     * Handle the Department "restored" event.
     */
    public function restored(Department $department): void
    {
        //
    }

    /**
     * Handle the Department "force deleted" event.
     */
    public function forceDeleted(Department $department): void
    {
        //
    }
}
