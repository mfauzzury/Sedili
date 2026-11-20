<?php

namespace App\Modules\Claims\Tests;

use App\Models\Employee;
use App\Modules\Claims\ChatTools\MyClaimsTool;
use App\Modules\Claims\Database\Seeders\ClaimTypeSeeder;
use App\Modules\Claims\Models\Claim;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What the Claims module lets the chat assistant look up: the claims of the employee who is chatting,
 * and nobody else's.
 */
class ClaimsChatToolTest extends TestCase
{
    use MakesClaims;
    use RefreshDatabase;

    private Employee $alice;

    private Employee $bob;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ClaimTypeSeeder::class);
        $this->alice = $this->employee('alice@example.com');
        $this->bob = $this->employee('bob@example.com');
    }

    public function test_the_claims_of_the_employee_are_listed_with_their_status_and_items(): void
    {
        $this->claim($this->alice, Claim::SUBMITTED, 80.0);
        $this->claim($this->bob, Claim::SUBMITTED, 999.0);

        $result = (new MyClaimsTool)->run($this->alice, []);

        $this->assertSame('MYR', $result['currency']);
        $this->assertCount(1, $result['claims']);
        $claim = $result['claims'][0];
        $this->assertSame('Client visit', $claim['title']);
        $this->assertSame('Submitted', $claim['status']);
        $this->assertSame(80.0, $claim['total']);
        $this->assertSame('Parking & Toll', $claim['items'][0]['type']);
        $this->assertSame(80.0, $claim['items'][0]['amount']);
        $this->assertStringNotContainsString('999', json_encode($result), "Bob's claim is not in it");
    }

    public function test_the_amounts_say_what_waits_for_approval_what_is_approved_and_what_was_paid(): void
    {
        $this->claim($this->alice, Claim::SUBMITTED, 10.0);
        $this->claim($this->alice, Claim::SUBMITTED, 20.0);
        $this->claim($this->alice, Claim::APPROVED, 40.0);
        $paid = $this->claim($this->alice, Claim::PAID, 100.0);
        $paid->update(['paid_at' => now()->toDateString(), 'payment_reference' => 'TXN-123']);
        $this->claim($this->alice, Claim::DRAFT, 5.0);
        $this->claim($this->bob, Claim::SUBMITTED, 777.0);

        $amounts = (new MyClaimsTool)->run($this->alice, [])['amounts'];

        $this->assertSame(30.0, $amounts['waiting_for_approval']);
        $this->assertSame(40.0, $amounts['approved_waiting_for_payment']);
        $this->assertSame(100.0, $amounts['paid_this_year']);
    }

    public function test_a_rejection_gives_its_reason_and_a_payment_its_reference_but_not_the_other_way_round(): void
    {
        $rejected = $this->claim($this->alice, Claim::REJECTED, 30.0);
        $rejected->update(['rejection_reason' => 'Receipt unreadable', 'payment_reference' => 'STALE-REF']);
        $paid = $this->claim($this->alice, Claim::PAID, 60.0);
        $paid->update(['paid_at' => now()->toDateString(), 'payment_reference' => 'TXN-9', 'rejection_reason' => 'stale reason']);

        $json = json_encode((new MyClaimsTool)->run($this->alice, []));

        $this->assertStringContainsString('Receipt unreadable', $json);
        $this->assertStringContainsString('TXN-9', $json);
        $this->assertStringNotContainsString('STALE-REF', $json);
        $this->assertStringNotContainsString('stale reason', $json);
    }

    public function test_the_claims_can_be_filtered_by_status_and_an_unknown_status_is_ignored(): void
    {
        $this->claim($this->alice, Claim::SUBMITTED);
        $this->claim($this->alice, Claim::APPROVED);

        $this->assertCount(1, (new MyClaimsTool)->run($this->alice, ['status' => 'Approved'])['claims']);
        $this->assertCount(2, (new MyClaimsTool)->run($this->alice, ['status' => 'Whatever'])['claims']);
        $this->assertCount(2, (new MyClaimsTool)->run($this->alice, ['year' => (int) now()->year])['claims']);
        $this->assertSame([], (new MyClaimsTool)->run($this->alice, ['year' => (int) now()->year - 3])['claims']);
    }

    public function test_the_employee_cannot_be_chosen_through_the_input(): void
    {
        $this->claim($this->bob, Claim::SUBMITTED, 555.0);

        $result = (new MyClaimsTool)->run($this->alice, ['employee_id' => $this->bob->id, 'email' => 'bob@example.com']);

        $this->assertSame([], $result['claims']);
        $this->assertSame(0.0, $result['amounts']['waiting_for_approval']);
    }

    public function test_the_receipt_is_never_part_of_it(): void
    {
        $this->claim($this->alice, Claim::SUBMITTED, 30.0, $this->storedReceipt());

        $json = json_encode((new MyClaimsTool)->run($this->alice, []));

        $this->assertStringNotContainsString('receipt', strtolower($json));
        $this->assertStringNotContainsString('.pdf', $json);
    }

    public function test_at_most_fifteen_claims_come_back(): void
    {
        foreach (range(1, 20) as $i) {
            $this->claim($this->alice, Claim::SUBMITTED, 1.0);
        }

        $this->assertCount(15, (new MyClaimsTool)->run($this->alice, [])['claims']);
    }
}
