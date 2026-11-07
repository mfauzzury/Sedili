<?php

namespace App\Modules\Leave\Database\Seeders;

use App\Modules\Leave\Models\Holiday;
use Illuminate\Database\Seeder;

/**
 * Malaysian national public holidays, as announced by the Cabinet, Constitution and
 * Inter-Governmental Relations Division of the Prime Minister's Department:
 * - 2026: circular GI/17/2025 (12 August 2025)
 * - 2027: circular GI/15/2026 (27 August 2026)
 * (both republished by the Federation of Malaysian Manufacturers, fmm.org.my)
 *
 * Included: the holidays marked N (national) in the circular, and the replacement day when a
 * national holiday falls on a Sunday (note 1 of the circular: the next day is substituted, and
 * if that day is also a public holiday, the day after it).
 * New Year's Day is a state holiday in most states (all but Johor, Kedah, Kelantan, Perlis and
 * Terengganu) and is included.
 * NOT included: the other state and Federal Territory holidays (Thaipusam, Federal Territory
 * Day, Nuzul Al-Quran, sultans' birthdays, Good Friday in Sabah and Sarawak, etc.): add the
 * ones of your state in the admin panel (Public Holidays).
 *
 * The dates marked * in the circular (Hari Raya Aidilfitri, Hari Raya Haji and Deepavali in 2027)
 * are subject to change: check them against the next announcement.
 * Holidays of the following years are published every August: add a new list here, or enter
 * them in the admin panel.
 */
class HolidaySeeder extends Seeder
{
    /** @var array<int, array<string, string>> year => date => name */
    public const HOLIDAYS = [
        2026 => [
            '2026-01-01' => "New Year's Day",
            '2026-02-17' => 'Chinese New Year',
            '2026-02-18' => 'Chinese New Year (2nd day)',
            '2026-03-21' => 'Hari Raya Aidilfitri',
            '2026-03-22' => 'Hari Raya Aidilfitri (2nd day)',
            '2026-03-23' => 'Replacement holiday: Hari Raya Aidilfitri (2nd day)',
            '2026-05-01' => 'Labour Day',
            '2026-05-27' => 'Hari Raya Haji',
            '2026-05-31' => 'Wesak Day',
            '2026-06-01' => "Agong's Birthday",
            '2026-06-02' => 'Replacement holiday: Wesak Day',
            '2026-06-17' => 'Awal Muharram',
            '2026-08-25' => "Prophet Muhammad's Birthday",
            '2026-08-31' => 'National Day (Merdeka)',
            '2026-09-16' => 'Malaysia Day',
            '2026-11-08' => 'Deepavali',
            '2026-11-09' => 'Replacement holiday: Deepavali',
            '2026-12-25' => 'Christmas Day',
        ],
        2027 => [
            '2027-01-01' => "New Year's Day",
            '2027-02-06' => 'Chinese New Year',
            '2027-02-07' => 'Chinese New Year (2nd day)',
            '2027-02-08' => 'Replacement holiday: Chinese New Year (2nd day)',
            '2027-03-10' => 'Hari Raya Aidilfitri',
            '2027-03-11' => 'Hari Raya Aidilfitri (2nd day)',
            '2027-05-01' => 'Labour Day',
            '2027-05-17' => 'Hari Raya Haji',
            '2027-05-20' => 'Wesak Day',
            '2027-06-06' => 'Awal Muharram',
            '2027-06-07' => "Agong's Birthday",
            '2027-06-08' => 'Replacement holiday: Awal Muharram',
            '2027-08-15' => "Prophet Muhammad's Birthday",
            '2027-08-16' => "Replacement holiday: Prophet Muhammad's Birthday",
            '2027-08-31' => 'National Day (Merdeka)',
            '2027-09-16' => 'Malaysia Day',
            '2027-10-28' => 'Deepavali',
            '2027-12-25' => 'Christmas Day',
        ],
    ];

    public function run(): void
    {
        foreach (self::HOLIDAYS as $holidays) {
            foreach ($holidays as $date => $name) {
                Holiday::updateOrCreate(['date' => $date], ['name' => $name]);
            }
        }
    }
}
