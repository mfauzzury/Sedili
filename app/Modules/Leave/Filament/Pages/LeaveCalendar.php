<?php

namespace App\Modules\Leave\Filament\Pages;

use App\Modules\Leave\Filament\Widgets\LeaveCalendarWidget;
use Filament\Pages\Page;

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
        return \Filament\Facades\Filament::getCurrentPanel()?->getId() === 'admin' ? 'HR Management' : null;
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
