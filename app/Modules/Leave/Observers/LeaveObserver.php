<?php

namespace App\Modules\Leave\Observers;

use App\Models\Employee;
use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Notifications\LeaveDecided;
use App\Modules\Leave\Notifications\LeaveRequested;
use App\Modules\Leave\Services\LeaveAttachment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sends the leave emails: the admins are told about a new request, the employee about the decision.
 * A mail server that is down must never prevent a leave from being saved, so failures are only reported.
 */
class LeaveObserver
{
    /**
     * Keep who decided: the person who is logged in when the status becomes Approved or Rejected
     * (the supervisors set it themselves, see LeaveApproval).
     */
    public function creating(Leave $leave): void
    {
        $this->recordDecider($leave);
    }

    public function updating(Leave $leave): void
    {
        if ($leave->isDirty('status')) {
            $this->recordDecider($leave);
        }
    }

    public function saving(Leave $leave): void
    {
        // The path comes from the browser with the form: only accept what the upload field produces
        if ($leave->isDirty('attachment') && filled($leave->attachment) && !LeaveAttachment::isValidPath($leave->attachment)) {
            throw ValidationException::withMessages(['attachment' => 'The document is not valid.']);
        }
    }

    public function saved(Leave $leave): void
    {
        // The document was replaced or removed: the previous file is no longer needed
        if ($leave->wasChanged('attachment')) {
            $this->deleteFile($leave->getOriginal('attachment'));
        }
    }

    public function deleted(Leave $leave): void
    {
        $this->deleteFile($leave->attachment);
    }

    private function deleteFile(?string $path): void
    {
        if (filled($path) && LeaveAttachment::isValidPath($path)) {
            Storage::disk(LeaveAttachment::DISK)->delete($path);
        }
    }

    private function recordDecider(Leave $leave): void
    {
        if ($this->isDecision($leave) && !$leave->isDirty('actioned_by') && Auth::check()) {
            $leave->actioned_by = Auth::id();
        }
    }

    public function created(Leave $leave): void
    {
        if ($leave->status === 'Pending') {
            $this->send(fn() => $this->notifyRequest($leave));
        } elseif ($this->isDecision($leave)) {
            $this->notifyEmployee($leave);
        }
    }

    public function updated(Leave $leave): void
    {
        if ($leave->wasChanged('status') && $this->isDecision($leave)) {
            $this->notifyEmployee($leave);
        }
    }

    /**
     * The admins and the supervisor of the employee are asked to decide (an admin who is also the
     * supervisor gets one email only, the one of the admins).
     */
    private function notifyRequest(Leave $leave): void
    {
        $admins = $this->admins();
        Notification::send($admins, new LeaveRequested($leave));

        $supervisor = $leave->employee?->effectiveSupervisor();
        if ($supervisor !== null && filled($supervisor->email) && !$admins->contains('id', $supervisor->id)) {
            Notification::send($supervisor, new LeaveRequested($leave, asSupervisor: true));
        }
    }

    private function isDecision(Leave $leave): bool
    {
        return in_array($leave->status, ['Approved', 'Rejected'], true);
    }

    private function notifyEmployee(Leave $leave): void
    {
        $employee = $leave->employee;
        if ($employee !== null && filled($employee->email)) {
            $this->send(fn() => $employee->notify(new LeaveDecided($leave)));
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Employee>
     */
    private function admins()
    {
        return Employee::role('admin')->where('is_active', true)->whereNotNull('email')->get();
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
