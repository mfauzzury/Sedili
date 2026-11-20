<?php

namespace App\Modules\Claims\Notifications;

use App\Modules\Claims\Filament\Portal\MyClaimResource;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Email to the employee when their claim is paid.
 */
class ClaimPaid extends ClaimNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $claim = $this->claim;

        $mail = (new MailMessage)
            ->subject('Your claim was paid')
            ->greeting("Hello {$notifiable->first_name},")
            ->line('Your claim was paid.')
            ->line($this->summary());

        if ($claim->paid_at !== null) {
            $mail->line('Paid on: '.$claim->paid_at->format('j M Y'));
        }
        if (filled($claim->payment_reference)) {
            $mail->line("Reference: {$claim->payment_reference}");
        }

        return $mail->action('View my claims', MyClaimResource::getUrl('index', panel: 'portal'));
    }
}
