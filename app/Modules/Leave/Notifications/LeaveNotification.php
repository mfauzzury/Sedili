<?php

namespace App\Modules\Leave\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Base of the leave emails: they are sent by the queue worker (php artisan queue:work), so a slow or
 * broken mail server never delays or breaks the request of the employee or the admin. They are only
 * queued once the database transaction that created the leave is committed, and tried 3 times. Each email calls $this->afterCommit() in its constructor
 * (the trait defines the property, so it cannot be declared again here).
 */
abstract class LeaveNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;
}
