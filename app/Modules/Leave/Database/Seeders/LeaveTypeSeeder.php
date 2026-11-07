<?php

namespace App\Modules\Leave\Database\Seeders;

use App\Modules\Leave\Models\LeaveType;
use Illuminate\Database\Seeder;

/**
 * Default leave types based on the Malaysian Employment Act 1955 minimum entitlements.
 * They can be edited in the admin panel (Leave Types).
 */
class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'name' => 'Annual Leave',
                'color' => '#2563eb',
                'tiers' => [
                    ['min_years' => 0, 'days' => 8],
                    ['min_years' => 2, 'days' => 12],
                    ['min_years' => 5, 'days' => 16],
                ],
            ],
            [
                'name' => 'Sick Leave',
                'color' => '#dc2626',
                'tiers' => [
                    ['min_years' => 0, 'days' => 14],
                    ['min_years' => 2, 'days' => 18],
                    ['min_years' => 5, 'days' => 22],
                ],
            ],
            ['name' => 'Hospitalisation Leave', 'color' => '#9333ea', 'days_per_year' => 60],
            [
                'name' => 'Maternity Leave',
                'color' => '#db2777',
                'days_per_year' => 98,
                'gender_restriction' => 'Female',
                'count_calendar_days' => true,
            ],
            ['name' => 'Paternity Leave', 'color' => '#0891b2', 'days_per_year' => 7, 'gender_restriction' => 'Male'],
            ['name' => 'Compassionate Leave', 'color' => '#475569', 'days_per_year' => 3],
            ['name' => 'Unpaid Leave', 'color' => '#d97706'],
        ];

        foreach ($types as $type) {
            // The colour is only a default: never overwrite the one chosen by an admin
            $color = $type['color'] ?? null;
            unset($type['color']);
            $leaveType = LeaveType::updateOrCreate(['name' => $type['name']], $type);
            if ($color !== null && blank($leaveType->color)) {
                $leaveType->update(['color' => $color]);
            }
        }
    }
}
