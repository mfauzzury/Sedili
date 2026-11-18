<?php

namespace App\Modules\Claims\Notifications;

use App\Modules\Claims\Models\Claim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Base of the claim emails: sent by the queue worker once the transaction is committed, tried 3
 * times (as the leave emails).
 */
abstract class ClaimNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public Claim $claim)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    protected function summary(): string
    {
        // The sample claims of the email previews are never saved: their items are set as a relation
        $count = $this->claim->relationLoaded('items')
            ? $this->claim->items->count()
            : $this->claim->items()->count();

        return sprintf(
            '%s: %s (%d item%s)',
            $this->claim->title,
            Claim::formatAmount($this->claim->total),
            $count,
            $count === 1 ? '' : 's'
        );
    }
}
