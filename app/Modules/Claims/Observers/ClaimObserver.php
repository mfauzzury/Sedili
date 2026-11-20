<?php

namespace App\Modules\Claims\Observers;

use App\Models\Employee;
use App\Modules\Claims\Models\Claim;
use App\Modules\Claims\Models\ClaimItem;
use App\Modules\Claims\Notifications\ClaimDecided;
use App\Modules\Claims\Notifications\ClaimPaid;
use App\Modules\Claims\Notifications\ClaimSubmitted;
use App\Modules\Claims\Services\ClaimReceipt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sends the claim emails when the status changes, and removes the receipts of a deleted claim
 * (its items are deleted by the database, without model events). A mail server that is down never
 * prevents a claim from being saved: failures are only reported.
 */
class ClaimObserver
{
    public function updated(Claim $claim): void
    {
        if (! $claim->wasChanged('status')) {
            return;
        }

        match ($claim->status) {
            Claim::SUBMITTED => $this->send(fn () => $this->notifySubmitted($claim)),
            Claim::APPROVED, Claim::REJECTED => $this->notifyEmployee($claim, new ClaimDecided($claim)),
            Claim::PAID => $this->notifyEmployee($claim, new ClaimPaid($claim)),
            default => null,
        };
    }

    public function deleting(Claim $claim): void
    {
        ClaimItem::query()
            ->where('claim_id', $claim->id)
            ->whereNotNull('receipt')
            ->pluck('receipt')
            ->filter(fn (string $path) => ClaimReceipt::isValidPath($path))
            ->each(fn (string $path) => Storage::disk(ClaimReceipt::DISK)->delete($path));
    }

    /**
     * The admins and the supervisor of the employee are asked to decide (an admin who is also the
     * supervisor gets one email only, the one of the admins).
     */
    private function notifySubmitted(Claim $claim): void
    {
        $employee = $claim->employee()->first();
        $admins = Employee::role('admin')
            ->where('is_active', true)
            ->whereNotNull('email')
            ->whereKeyNot($claim->employee_id)
            ->get();
        Notification::send($admins, new ClaimSubmitted($claim));

        $supervisor = $employee?->effectiveSupervisor();
        if ($supervisor !== null && filled($supervisor->email) && ! $admins->contains('id', $supervisor->id)) {
            Notification::send($supervisor, new ClaimSubmitted($claim, asSupervisor: true));
        }
    }

    private function notifyEmployee(Claim $claim, \Illuminate\Notifications\Notification $notification): void
    {
        $employee = $claim->employee()->first();
        if ($employee !== null && filled($employee->email)) {
            $this->send(fn () => $employee->notify($notification));
        }
    }

    private function send(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
