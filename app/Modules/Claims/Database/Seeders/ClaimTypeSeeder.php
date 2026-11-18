<?php

namespace App\Modules\Claims\Database\Seeders;

use App\Modules\Claims\Models\ClaimType;
use Illuminate\Database\Seeder;

/**
 * Default claim types. They can be edited in the admin panel (Claim Types). Can be run again: an
 * existing type is left as it is.
 */
class ClaimTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Travel', 'description' => 'Flights, trains, buses, taxis and e-hailing.', 'requires_receipt' => true],
            ['name' => 'Meals', 'description' => 'Meals during work travel or with clients.', 'requires_receipt' => true],
            ['name' => 'Accommodation', 'description' => 'Hotels during work travel.', 'requires_receipt' => true],
            ['name' => 'Parking & Toll'],
            ['name' => 'Mileage', 'description' => 'Use of your own vehicle for work.', 'is_mileage' => true, 'rate_per_km' => 0.60],
            ['name' => 'Medical', 'description' => 'Outpatient treatment and medicine.', 'requires_receipt' => true],
            ['name' => 'Others', 'requires_receipt' => true],
        ];

        foreach ($types as $sort => $type) {
            ClaimType::firstOrCreate(['name' => $type['name']], [...$type, 'sort' => ($sort + 1) * 10]);
        }
    }
}
