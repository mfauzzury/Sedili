<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Malaysian federal public holidays that fall on a fixed date (current and next year).
 * Holidays that depend on the lunar calendar (Chinese New Year, Hari Raya, Deepavali, etc.)
 * and state holidays change every year: add them in the admin panel (Public Holidays).
 */
class HolidaySeeder extends Seeder
{
    public function run(): void
    {
        $year = (int) now()->year;
        foreach ([$year, $year + 1] as $y) {
            $holidays = [
                "$y-01-01" => "New Year's Day",
                "$y-05-01" => 'Labour Day',
                // Birthday of the Yang di-Pertuan Agong: first Monday of June
                Carbon::create($y, 6, 1)->next(Carbon::MONDAY)->toDateString() => "Agong's Birthday",
                "$y-08-31" => 'National Day (Merdeka)',
                "$y-09-16" => 'Malaysia Day',
                "$y-12-25" => 'Christmas Day',
            ];
            // next() skips June 1st itself when it is already a Monday
            if (Carbon::create($y, 6, 1)->isMonday()) {
                unset($holidays[Carbon::create($y, 6, 1)->next(Carbon::MONDAY)->toDateString()]);
                $holidays["$y-06-01"] = "Agong's Birthday";
            }
            foreach ($holidays as $date => $name) {
                Holiday::updateOrCreate(['date' => $date], ['name' => $name]);
            }
        }
    }
}
