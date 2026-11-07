<?php

namespace App\Modules\Leave\Filament\Pages;

use App\Modules\Leave\Filament\Portal\MyLeaveResource;
use App\Modules\Leave\Filament\Widgets\LeaveCalendarWidget;
use App\Modules\Leave\Models\LeaveType;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Calendar of the staff on leave (admin panel and employee portal, see LeaveCalendarWidget).
 */
class LeaveCalendar extends Page
{
    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-calendar-days';
    protected static string|\UnitEnum|null $navigationGroup = null;
    protected static ?string $title = 'Leave Calendar';
    protected static ?string $slug = 'leave-calendar';
    protected static ?int $navigationSort = 2;

    protected string $view = 'leave::leave-calendar';

    public static function getNavigationGroup(): ?string
    {
        // In the admin panel the page sits in HR Management; the portal has a flat menu
        return Filament::getCurrentPanel()?->getId() === 'admin' ? 'HR Management' : null;
    }

    public function isAdminPanel(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }

    /**
     * Legend of the colours: the leave types (the ones the employee may take in the portal, plus the
     * colour of the colleagues, whose leave type stays private) and the public holidays.
     *
     * @return list<array{label: string, color: string}>
     */
    public function getLegend(): array
    {
        $legend = [];
        if ($this->isAdminPanel()) {
            foreach (LeaveType::where('is_active', true)->orderBy('id')->get() as $type) {
                $legend[] = ['label' => $type->name, 'color' => $type->displayColor()];
            }
        } else {
            $legend[] = ['label' => 'Colleague on leave', 'color' => LeaveCalendarWidget::COLLEAGUE_COLOR];
            $eligible = MyLeaveResource::eligibleTypes(Auth::user());
            foreach (LeaveType::whereIn('name', $eligible)->orderBy('id')->get() as $type) {
                $legend[] = ['label' => "My {$type->name}", 'color' => $type->displayColor()];
            }
        }
        $legend[] = ['label' => 'Public holiday', 'color' => LeaveCalendarWidget::HOLIDAY_COLOR];

        return $legend;
    }

    protected function getFooterWidgets(): array
    {
        return [LeaveCalendarWidget::class];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }
}
