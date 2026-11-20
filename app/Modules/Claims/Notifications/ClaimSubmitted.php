<?php

namespace App\Modules\Claims\Notifications;

use App\Modules\Claims\Filament\Portal\TeamClaimResource;
use App\Modules\Claims\Filament\Resources\Claims\ClaimResource;
use App\Modules\Claims\Models\Claim;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Email when an employee submits a claim: to the admins (admin panel) and to the supervisor of the
 * employee (portal, Team Claims).
 */
class ClaimSubmitted extends ClaimNotification
{
    public function __construct(Claim $claim, public bool $asSupervisor = false)
    {
        parent::__construct($claim);
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Loaded again by the queue worker: lazy loading is forbidden, so the relation is asked for
        $claim = $this->claim->loadMissing('employee');
        $employee = $claim->employee;

        $mail = (new MailMessage)
            ->subject("Claim from {$employee->name}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line($this->asSupervisor
                ? "{$employee->name}, who reports to you, submitted a claim and is waiting for your decision."
                : "{$employee->name} submitted a claim and is waiting for a decision.")
            ->line($this->summary());

        if (filled($claim->notes)) {
            $mail->line("Notes: {$claim->notes}");
        }

        return $mail->action(
            'Review the claim',
            $this->asSupervisor
                ? TeamClaimResource::getUrl('index', panel: 'portal')
                : ClaimResource::getUrl('index', panel: 'admin')
        );
    }
}
