<?php

namespace App\Modules\Leave\Notifications;

use App\Modules\Leave\Filament\Portal\TeamLeaveResource;
use App\Modules\Leave\Filament\Resources\Leaves\LeaveResource;
use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Services\LeaveService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email when an employee requests leave: to the admins, who decide in the admin panel, and to the
 * supervisor of the employee, who decides in the portal (Team Leave).
 */
class LeaveRequested extends Notification
{
    public function __construct(public Leave $leave, public bool $asSupervisor = false)
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
        $employee = $leave->employee;

        $mail = (new MailMessage())
            ->subject("Leave request from {$employee->name}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line($this->asSupervisor
                ? "{$employee->name}, who reports to you, requested leave and is waiting for your decision."
                : "{$employee->name} requested leave and is waiting for your decision.")
            ->line("Type: {$leave->leave_type}")
            ->line('Dates: ' . LeaveDates::format($leave))
            ->line('Days: ' . app(LeaveService::class)->format($leave->days));

        if (filled($leave->notes)) {
            $mail->line("Notes: {$leave->notes}");
        }
        if (filled($leave->attachment)) {
            $mail->line('A document is attached to the request.');
        }

        return $mail->action(
            'Review the request',
            $this->asSupervisor
                ? TeamLeaveResource::getUrl('index', panel: 'portal')
                : LeaveResource::getUrl('index', panel: 'admin')
        );
    }
}
