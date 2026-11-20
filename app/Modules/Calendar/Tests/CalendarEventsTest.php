<?php

namespace App\Modules\Calendar\Tests;

use App\Modules\Calendar\Filament\Widgets\AllCalendarWidget;
use App\Modules\Calendar\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesEmployees;
use Tests\TestCase;

class CalendarEventsTest extends TestCase
{
    use CreatesEmployees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdminPanel();
        $this->loginAsAdmin();
    }

    private function event(string $title, string $start, string $end, array $extra = []): Event
    {
        return Event::create([
            'title' => $title,
            'start_time' => $start,
            'end_time' => $end,
            'all_day' => false,
            'type' => 'meeting',
            ...$extra,
        ]);
    }

    /**
     * Titles of the events the calendar fetches when it displays November 2026.
     *
     * @return list<string>
     */
    private function titlesOfNovember(): array
    {
        $events = Livewire::test(AllCalendarWidget::class)->instance()->getEventsJs([
            'startStr' => '2026-11-01T00:00:00',
            'endStr' => '2026-12-01T00:00:00',
            'tzOffset' => 0,
        ]);

        return collect($events)->pluck('title')->sort()->values()->all();
    }

    public function test_an_event_inside_the_displayed_month_is_shown(): void
    {
        $this->event('Inside', '2026-11-10 09:00', '2026-11-10 10:00');

        $this->assertSame(['Inside'], $this->titlesOfNovember());
    }

    public function test_events_outside_the_displayed_month_are_not_shown(): void
    {
        $this->event('Before', '2026-10-10 09:00', '2026-10-10 10:00');
        $this->event('After', '2026-12-10 09:00', '2026-12-10 10:00');

        $this->assertSame([], $this->titlesOfNovember());
    }

    public function test_an_event_that_starts_before_the_month_and_ends_inside_it_is_shown(): void
    {
        $this->event('Crossing the start', '2026-10-30 09:00', '2026-11-03 18:00');

        $this->assertSame(['Crossing the start'], $this->titlesOfNovember());
    }

    public function test_an_event_that_starts_inside_the_month_and_ends_after_it_is_shown(): void
    {
        $this->event('Crossing the end', '2026-11-29 09:00', '2026-12-02 18:00');

        $this->assertSame(['Crossing the end'], $this->titlesOfNovember());
    }

    public function test_an_event_that_covers_the_whole_month_is_shown(): void
    {
        $this->event('Covering', '2026-10-15 09:00', '2026-12-15 18:00');

        $this->assertSame(['Covering'], $this->titlesOfNovember());
    }

    public function test_the_colour_depends_on_the_type_of_event(): void
    {
        $colours = collect(['meeting', 'appointment', 'deadline', 'event'])
            ->map(fn ($type) => $this->event($type, '2026-11-10 09:00', '2026-11-10 10:00', ['type' => $type])
                ->toCalendarEvent()->getBackgroundColor());

        $this->assertSame(['#3b82f6', '#10b981', '#ef4444', '#8b5cf6'], $colours->all());
    }

    public function test_dropping_an_event_moves_it(): void
    {
        $event = $this->event('Move me', '2026-11-10 09:00', '2026-11-10 10:00');
        // The browser sends the event back with its styles and classes as lists
        $before = array_merge($event->toCalendarEvent()->toCalendarObject(0, false), ['styles' => [], 'classNames' => [], 'display' => 'auto', 'resourceIds' => []]);
        $after = array_merge($before, ['start' => '2026-11-12T09:00:00Z', 'end' => '2026-11-12T10:00:00Z']);
        $view = [
            'type' => 'dayGridMonth', 'title' => 'November 2026',
            'currentStart' => '2026-11-01T00:00:00Z', 'currentEnd' => '2026-12-01T00:00:00Z',
            'activeStart' => '2026-10-26T00:00:00Z', 'activeEnd' => '2026-12-07T00:00:00Z',
        ];

        Livewire::test(AllCalendarWidget::class)
            ->call('onEventDropJs', ['event' => $after, 'oldEvent' => $before, 'tzOffset' => 0, 'view' => $view]);

        $this->assertSame('2026-11-12 09:00', $event->fresh()->start_time->format('Y-m-d H:i'));
        $this->assertSame('2026-11-12 10:00', $event->fresh()->end_time->format('Y-m-d H:i'));
    }
}
