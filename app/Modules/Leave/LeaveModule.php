<?php

namespace App\Modules\Leave;

use App\Modules\Leave\Database\Seeders\HolidaySeeder;
use App\Modules\Leave\Database\Seeders\LeaveTypeSeeder;
use App\Modules\Leave\Filament\Pages\LeaveBalances;
use App\Modules\Leave\Filament\Pages\LeaveCalendar;
use App\Modules\Leave\Filament\Portal\MyLeaveResource;
use App\Modules\Leave\Filament\Portal\TeamLeaveResource;
use App\Modules\Leave\Filament\Widgets\LeaveCalendarWidget;
use App\Modules\Leave\Filament\Widgets\MyLeaveBalances;
use App\Modules\Leave\Filament\Resources\Holidays\HolidayResource;
use App\Modules\Leave\Filament\Resources\LeaveTypes\LeaveTypeResource;
use App\Modules\Leave\Filament\Resources\Leaves\LeaveResource;
use App\Models\Employee;
use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Notifications\LeaveDecided;
use App\Modules\Leave\Notifications\LeaveRequested;
use App\Modules\Leave\Observers\LeaveObserver;
use App\Modules\Leave\Http\LeaveAttachmentController;
use App\Modules\Leave\Reports\LeaveBalanceReport;
use App\Modules\Leave\Reports\LeaveTakenReport;
use App\Modules\Module;
use App\Modules\Todo;
use Illuminate\Support\Facades\Route;

class LeaveModule extends Module
{
    public function key(): string
    {
        return 'leave';
    }

    public function label(): string
    {
        return 'Leave';
    }

    public function description(): string
    {
        return 'Leave requests, leave types and entitlements, public holidays, leave balances and the leave calendar; employees request their own leave in the portal.';
    }

    public function filament(string $panelId): array
    {
        return match ($panelId) {
            'admin' => [
                'resources' => [LeaveResource::class, LeaveTypeResource::class, HolidayResource::class],
                'pages' => [LeaveBalances::class, LeaveCalendar::class],
                'livewire' => [LeaveCalendarWidget::class],
            ],
            // Employees request their own leave in the portal
            'portal' => [
                'resources' => [MyLeaveResource::class, TeamLeaveResource::class],
                'pages' => [LeaveCalendar::class],
                'widgets' => [MyLeaveBalances::class],
                'livewire' => [LeaveCalendarWidget::class],
            ],
            default => [],
        };
    }

    /**
     * One line with the leave requests that wait for the decision of the employee (a supervisor).
     */
    public function todos(Employee $employee, string $panelId): array
    {
        if ($panelId !== 'portal') {
            return [];
        }

        $requests = Leave::query()
            ->with('employee')
            ->where('status', 'Pending')
            ->whereIn('employee_id', Employee::query()->reportingTo($employee)->select('employees.id'))
            ->orderBy('start_date')
            ->get();
        if ($requests->isEmpty()) {
            return [];
        }

        $names = $requests->map(fn(Leave $leave) => $leave->employee?->first_name)->filter()->unique();
        $shown = $names->take(3)->implode(', ');
        $detail = $names->count() > 3 ? $shown . ' and ' . ($names->count() - 3) . ' more' : $shown;

        return [new Todo(
            title: $requests->count() === 1 ? '1 leave request to approve' : $requests->count() . ' leave requests to approve',
            url: TeamLeaveResource::getUrl(),
            detail: $detail ?: null,
            badge: 'From ' . $requests->first()->start_date->format('j M'),
            color: 'warning',
            icon: 'heroicon-o-user-minus',
        )];
    }

    public function emailPreviews(): array
    {
        // Sample leave, never saved: the admin who previews is the employee of the request
        $sample = function (Employee $employee, string $status): Leave {
            $leave = new Leave([
                'employee_id' => $employee->id,
                'leave_type' => 'Annual Leave',
                'start_date' => now()->addWeek()->startOfWeek(),
                'end_date' => now()->addWeek()->startOfWeek()->addDays(2),
                'days' => 3,
                'status' => $status,
                'actioned_by' => $status === 'Pending' ? null : $employee->id,
                'rejection_reason' => $status === 'Rejected' ? 'The team is short-staffed that week.' : null,
                'notes' => 'Family trip',
            ]);
            return $leave->setRelation('employee', $employee);
        };

        return [
            'Leave requested' => fn(Employee $to) => (new LeaveRequested($sample($to, 'Pending')))->toMail($to),
            'Leave requested (email to the supervisor)' => fn(Employee $to) => (new LeaveRequested($sample($to, 'Pending'), asSupervisor: true))->toMail($to),
            'Leave approved' => fn(Employee $to) => (new LeaveDecided($sample($to, 'Approved')))->toMail($to),
            'Leave rejected' => fn(Employee $to) => (new LeaveDecided($sample($to, 'Rejected')))->toMail($to),
        ];
    }

    public function boot(): void
    {
        Leave::observe(LeaveObserver::class);

        // Documents attached to the requests; the controller checks who may open them (no "auth"
        // middleware: it would redirect a guest to a login route that does not exist)
        Route::middleware('web')
            ->get('leave-attachments/{leave}', LeaveAttachmentController::class)
            ->name('leave.attachment');
    }

    public function seeders(): array
    {
        return [LeaveTypeSeeder::class, HolidaySeeder::class];
    }

    public function reports(): array
    {
        return [app(LeaveTakenReport::class), app(LeaveBalanceReport::class)];
    }
}
