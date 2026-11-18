<?php

namespace App\Modules\Claims\Tests;

use App\Models\Employee;
use App\Modules\Claims\Database\Seeders\ClaimTypeSeeder;
use App\Modules\Claims\Models\Claim;
use App\Modules\Claims\Models\ClaimItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClaimItemTest extends TestCase
{
    use MakesClaims;
    use RefreshDatabase;

    private Employee $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ClaimTypeSeeder::class);
        $this->alice = $this->employee('alice@example.com');
    }

    private function mileage(Claim $claim, float $km, float $amount = 999): ClaimItem
    {
        return ClaimItem::create([
            'claim_id' => $claim->id,
            'claim_type_id' => $this->type('Mileage')->id,
            'date' => '2026-11-10',
            'description' => 'Office to client',
            'distance_km' => $km,
            'amount' => $amount,
        ]);
    }

    public function test_a_mileage_amount_is_the_distance_times_the_rate_whatever_was_sent(): void
    {
        $claim = $this->claim($this->alice);

        $item = $this->mileage($claim, 25, amount: 999);

        $this->assertSame('0.60', $item->fresh()->rate);
        $this->assertSame('15.00', $item->fresh()->amount);
        $this->assertSame('65.00', $claim->fresh()->total);
    }

    public function test_the_rate_of_an_item_is_kept_when_the_rate_of_the_type_changes_later(): void
    {
        $claim = $this->claim($this->alice);
        $item = $this->mileage($claim, 10);

        $this->type('Mileage')->update(['rate_per_km' => 1.00]);
        $item->update(['distance_km' => 20]);

        $this->assertSame('0.60', $item->fresh()->rate);
        $this->assertSame('12.00', $item->fresh()->amount);
    }

    public function test_a_non_mileage_item_keeps_no_distance(): void
    {
        $claim = $this->claim($this->alice);
        $item = $claim->items()->first();
        $item->update(['distance_km' => 30, 'rate' => 5]);

        $this->assertNull($item->fresh()->distance_km);
        $this->assertNull($item->fresh()->rate);
        $this->assertSame('50.00', $item->fresh()->amount);
    }

    public function test_the_total_follows_the_items(): void
    {
        $claim = $this->claim($this->alice, amount: 40);
        $this->assertSame('40.00', $claim->total);

        $second = ClaimItem::create([
            'claim_id' => $claim->id,
            'claim_type_id' => $this->type('Parking & Toll')->id,
            'date' => '2026-11-10',
            'description' => 'Parking',
            'amount' => 7.5,
        ]);
        $this->assertSame('47.50', $claim->fresh()->total);

        $second->update(['amount' => 10]);
        $this->assertSame('50.00', $claim->fresh()->total);

        $second->delete();
        $this->assertSame('40.00', $claim->fresh()->total);
    }

    public function test_an_amount_must_be_more_than_zero(): void
    {
        $claim = $this->claim($this->alice);

        $this->expectException(ValidationException::class);
        ClaimItem::create([
            'claim_id' => $claim->id,
            'claim_type_id' => $this->type('Meals')->id,
            'date' => '2026-11-10',
            'description' => 'Nothing',
            'amount' => 0,
        ]);
    }

    public function test_the_items_of_a_submitted_claim_cannot_change(): void
    {
        $claim = $this->claim($this->alice, Claim::SUBMITTED);
        $item = $claim->items()->first();

        $this->assertThrows(fn () => $item->update(['amount' => 500]), ValidationException::class);
        $this->assertThrows(fn () => $item->delete(), ValidationException::class);

        $this->assertSame('50.00', $item->fresh()->amount);
        $this->assertSame('50.00', $claim->fresh()->total);
    }

    public function test_seeding_again_keeps_the_changes_of_an_admin(): void
    {
        $this->type('Mileage')->update(['rate_per_km' => 0.80]);

        $this->seed(ClaimTypeSeeder::class);

        $this->assertSame('0.80', $this->type('Mileage')->rate_per_km);
    }
}
