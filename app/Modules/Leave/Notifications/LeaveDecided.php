<?php

namespace App\Modules\Leave\Notifications;

use App\Models\Employee;
use App\Modules\Leave\Filament\Portal\MyLeaveResource;
use App\Modules\Leave\Models\Leave;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email to the employee when their leave request is approved or rejected.
 */
class LeaveDecided extends Notification
{
    public function __construct(public Leave $leave)
    {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $leave = $this->leave;
        $approved = $leave->status === 'Approved';
        $decision = $approved ? 'approved' : 'rejected';

        $mail = (new MailMessage())
            ->subject("Your leave request was {$decision}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("Your {$leave->leave_type} request for " . LeaveDates::format($leave) . " was {$decision}.");

        $decider = $leave->actioned_by ? Employee::find($leave->actioned_by) : null;
        if ($decider !== null) {
            $mail->line("Decided by: {$decider->name}");
        }

        if (!$approved && filled($leave->rejection_reason)) {
            $mail->line("Reason: {$leave->rejection_reason}");
        }

        return $mail->action('View my leave', MyLeaveResource::getUrl('index', panel: 'portal'));
    }
}
