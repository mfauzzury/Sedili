<?php

namespace App\Modules\Leave\Filament\Widgets;

use App\Models\Department;
use App\Modules\Leave\Models\Holiday;
use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Models\LeaveType;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Calendar of the staff on leave, with the public holidays.
 *
 * - Admin panel: every approved and pending leave, with the leave type; can be filtered by
 *   department and leave type.
 * - Employee portal: the approved leave of the colleagues (name and dates only: the leave type
 *   and the reason stay private), and the employee's own leave in full, pending included.
 * Rejected leave is never displayed.
 */
class LeaveCalendarWidget extends CalendarWidget
{
    protected static ?string $title = 'Leave Calendar';

    protected bool $eventClickEnabled = false;
    protected bool $dateClickEnabled = false;
    protected bool $eventDragEnabled = false;
    protected bool $eventResizeEnabled = false;
    protected bool $dateSelectEnabled = false;

    // A busy day shows as many events as the cell can hold and a "+N more" link instead of
    // stretching the whole week. "Fit the cell" needs a fixed height: the weeks share it.
    protected bool $dayMaxEvents = true;
    protected array $options = ['height' => '760px'];

    public ?int $departmentId = null;
    public ?string $leaveType = null;

    /** Offset of the browser in minutes ahead of UTC (+480 in Malaysia), received with every fetch */
    private int $tzOffset = 0;

    public function getEventsJs(array $info): array
    {
        $this->tzOffset = (int) data_get($info, 'tzOffset', 0);

        return parent::getEventsJs($info);
    }

    /**
     * Midnight of a date in the timezone of the viewer. The library shifts the times by the offset
     * of the browser: a plain midnight (UTC) would be displayed at 08:00 in Malaysia and an all day
     * event would spill over the next day.
     */
    private function localDay(CarbonInterface $date): Carbon
    {
        return Carbon::parse($date->toDateString() . ' 00:00:00', 'UTC')->subMinutes($this->tzOffset);
    }

    public function isAdminPanel(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }

    public function getHeaderActions(): array
    {
        if (!$this->isAdminPanel()) {
            return [];
        }

        return [
            Action::make('filter')
                ->label(fn() => ($this->departmentId || $this->leaveType) ? 'Filter (active)' : 'Filter')
                ->icon('heroicon-o-funnel')
                ->color('gray')
                ->modalWidth('md')
                ->modalSubmitActionLabel('Apply')
                ->fillForm(fn() => ['departmentId' => $this->departmentId, 'leaveType' => $this->leaveType])
                ->schema([
                    Select::make('departmentId')
                        ->label('Department')
                        ->options(fn() => Department::orderBy('name')->pluck('name', 'id'))
                        ->placeholder('All departments'),
                    Select::make('leaveType')
                        ->label('Leave type')
                        ->options(fn() => LeaveType::orderBy('id')->pluck('name', 'name'))
                        ->placeholder('All leave types'),
                ])
                ->action(function (array $data) {
                    $this->departmentId = filled($data['departmentId'] ?? null) ? (int) $data['departmentId'] : null;
                    $this->leaveType = filled($data['leaveType'] ?? null) ? $data['leaveType'] : null;
                    $this->refreshRecords();
                }),
        ];
    }

    protected function getEvents(FetchInfo $info): Collection|array|Builder
    {
        // Plain arrays: an empty Eloquent collection would try to merge the events as models
        return collect([...$this->leaveEvents($info)->all(), ...$this->holidayEvents($info)->all()]);
    }

    /**
     * @return Collection<int, CalendarEvent>
     */
    private function leaveEvents(FetchInfo $info): Collection
    {
        $me = (int) Auth::id();
        $isAdmin = $this->isAdminPanel();

        return Leave::query()
            ->with('employee')
            ->whereDate('start_date', '<', $info->end->toDateString())
            ->whereDate('end_date', '>=', $info->start->toDateString())
            ->where(function (Builder $query) use ($isAdmin, $me) {
                $query->where('status', 'Approved');
                // Pending leave: everything for an admin, only their own for an employee
                $isAdmin
                    ? $query->orWhere('status', 'Pending')
                    : $query->orWhere(fn(Builder $own) => $own->where('status', 'Pending')->where('employee_id', $me));
            })
            ->when($isAdmin && $this->departmentId, fn(Builder $query) => $query
                ->whereHas('employee', fn(Builder $employee) => $employee->where('department_id', $this->departmentId)))
            ->when($isAdmin && $this->leaveType, fn(Builder $query) => $query->where('leave_type', $this->leaveType))
            ->orderBy('start_date')
            ->get()
            ->map(function (Leave $leave) use ($isAdmin, $me) {
                $own = (int) $leave->employee_id === $me;
                $pending = $leave->status === 'Pending';
                $name = $leave->employee?->name ?? 'Unknown';

                $title = match (true) {
                    $isAdmin => "{$name} - {$leave->leave_type}",
                    $own => "Me - {$leave->leave_type}",
                    default => $name, // colleagues: no leave type
                };
                if ($pending) {
                    $title .= ' (pending)';
                }

                return CalendarEvent::make()
                    ->key('leave-' . $leave->id)
                    ->title($title)
                    ->start($this->localDay($leave->start_date))
                    // The end of an all day event is exclusive
                    ->end($this->localDay($leave->end_date)->addDay())
                    ->allDay()
                    ->backgroundColor($pending ? '#fde68a' : ($own && !$isAdmin ? '#0891b2' : '#16a34a'))
                    ->textColor($pending ? '#78350f' : '#ffffff');
            });
    }

    /**
     * @return Collection<int, CalendarEvent>
     */
    private function holidayEvents(FetchInfo $info): Collection
    {
        return Holiday::query()
            ->whereDate('date', '>=', $info->start->toDateString())
            ->whereDate('date', '<', $info->end->toDateString())
            ->get()
            ->map(fn(Holiday $holiday) => CalendarEvent::make()
                ->key('holiday-' . $holiday->id)
                ->title($holiday->name)
                ->start($this->localDay($holiday->date))
                ->end($this->localDay($holiday->date)->addDay())
                ->allDay()
                ->backgroundColor('#e5e7eb')
                ->textColor('#374151'));
    }
}
