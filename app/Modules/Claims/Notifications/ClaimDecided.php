<?php

namespace App\Modules\Claims\Notifications;

use App\Models\Employee;
use App\Modules\Claims\Filament\Portal\MyClaimResource;
use App\Modules\Claims\Models\Claim;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Email to the employee when their claim is approved or rejected.
 */
class ClaimDecided extends ClaimNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $claim = $this->claim;
        $decision = $claim->status === Claim::APPROVED ? 'approved' : 'rejected';

        $mail = (new MailMessage)
            ->subject("Your claim was {$decision}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("Your claim was {$decision}.")
            ->line($this->summary());

        $decider = $claim->actioned_by ? Employee::find($claim->actioned_by) : null;
        if ($decider !== null) {
            $mail->line("Decided by: {$decider->name}");
        }
        if ($claim->status === Claim::REJECTED && filled($claim->rejection_reason)) {
            $mail->line("Reason: {$claim->rejection_reason}");
        }
        if ($claim->status === Claim::APPROVED) {
            $mail->line('You will be told when it is paid.');
        }

        return $mail->action('View my claims', MyClaimResource::getUrl('index', panel: 'portal'));
    }
}
