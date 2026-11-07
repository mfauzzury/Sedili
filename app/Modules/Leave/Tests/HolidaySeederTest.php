<?php

namespace App\Modules\Leave\Tests;

use App\Modules\Leave\Database\Seeders\HolidaySeeder;
use App\Modules\Leave\Models\Holiday;
use App\Modules\Leave\Services\LeaveService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidaySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(HolidaySeeder::class);
        $count = Holiday::count();
        $this->seed(HolidaySeeder::class);

        $this->assertSame($count, Holiday::count());
        $this->assertSame(36, $count); // 18 holidays in 2026 and 18 in 2027
    }

    public function test_a_holiday_on_a_sunday_is_replaced_by_the_next_working_day(): void
    {
        $this->seed(HolidaySeeder::class);

        // Awal Muharram 2027 is a Sunday; Monday is the Agong's Birthday, so Tuesday replaces it
        $this->assertTrue(Carbon::parse('2027-06-06')->isSunday());
        $this->assertDatabaseHas('holidays', ['date' => '2027-06-07', 'name' => "Agong's Birthday"]);
        $this->assertDatabaseHas('holidays', ['date' => '2027-06-08', 'name' => 'Replacement holiday: Awal Muharram']);
    }

    public function test_every_replacement_holiday_follows_a_sunday_holiday(): void
    {
        $this->seed(HolidaySeeder::class);

        foreach (Holiday::where('name', 'like', 'Replacement holiday%')->get() as $replacement) {
            $this->assertFalse($replacement->date->isWeekend(), "{$replacement->date->toDateString()} is a weekend");
            // A Sunday holiday sits within 2 days before its replacement (3 when two holidays follow each other)
            $this->assertTrue(
                Holiday::whereBetween('date', [
                    $replacement->date->copy()->subDays(3)->toDateString(),
                    $replacement->date->copy()->subDay()->toDateString(),
                ])->get()->contains(fn($holiday) => $holiday->date->isSunday()),
                "No Sunday holiday before {$replacement->date->toDateString()}"
            );
        }
    }

    public function test_hari_raya_2027_is_not_counted_as_leave(): void
    {
        $this->seed(HolidaySeeder::class);

        // Monday 8 to Friday 12 March 2027: Hari Raya Aidilfitri on Wednesday 10 and Thursday 11
        $this->assertSame(3.0, app(LeaveService::class)->countDays(Carbon::parse('2027-03-08'), Carbon::parse('2027-03-12')));
    }
}
