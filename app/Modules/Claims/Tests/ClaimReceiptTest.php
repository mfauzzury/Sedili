<?php

namespace App\Modules\Claims\Tests;

use App\Models\Employee;
use App\Modules\Claims\Database\Seeders\ClaimTypeSeeder;
use App\Modules\Claims\Models\Claim;
use App\Modules\Claims\Services\ClaimReceipt;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClaimReceiptTest extends TestCase
{
    use MakesClaims;
    use RefreshDatabase;

    private Employee $alice;

    private Employee $boss;

    private Employee $other;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ClaimReceipt::DISK);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ClaimTypeSeeder::class);

        $this->boss = $this->employee('boss@example.com');
        $this->alice = $this->employee('alice@example.com', ['supervisor_id' => $this->boss->id]);
        $this->other = $this->employee('other@example.com');
        $this->admin = $this->employee('admin@example.com', [], 'admin');
    }

    public function test_the_owner_their_supervisor_and_the_admins_open_a_receipt_and_nobody_else(): void
    {
        $item = $this->claim($this->alice, receipt: $this->storedReceipt())->items()->first();
        $url = ClaimReceipt::url($item);

        $this->get($url)->assertForbidden();
        $this->actingAs($this->other)->get($url)->assertForbidden();

        $this->actingAs($this->alice)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->boss)->get($url)->assertOk();
        $this->actingAs($this->admin)->get($url)->assertOk();
    }

    public function test_a_path_that_the_upload_field_did_not_produce_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->claim($this->alice, receipt: '../.env');
    }

    public function test_a_replaced_receipt_is_deleted(): void
    {
        $old = $this->storedReceipt();
        $item = $this->claim($this->alice, receipt: $old)->items()->first();
        $new = $this->storedReceipt();

        $item->update(['receipt' => $new]);

        Storage::disk(ClaimReceipt::DISK)->assertMissing($old);
        Storage::disk(ClaimReceipt::DISK)->assertExists($new);
    }

    public function test_an_item_without_receipt_has_no_link(): void
    {
        $item = $this->claim($this->alice, Claim::DRAFT)->items()->first();

        $this->assertNull(ClaimReceipt::url($item));
    }
}
