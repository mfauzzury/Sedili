<?php

namespace App\Modules\Calendar;

use App\Modules\Calendar\Filament\Widgets\AllCalendarWidget;
use App\Modules\Module;

class CalendarModule extends Module
{
    public function key(): string
    {
        return 'calendar';
    }

    public function label(): string
    {
        return 'Calendar';
    }

    public function description(): string
    {
        return 'Shared calendar of events displayed on the dashboard.';
    }

    public function filament(string $panelId): array
    {
        return ['widgets' => [AllCalendarWidget::class]];
    }
}
