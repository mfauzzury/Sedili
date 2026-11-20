<?php

namespace App\Modules\Claims\Tests;

use App\Models\Employee;
use App\Modules\Claims\Database\Seeders\ClaimTypeSeeder;
use App\Modules\Claims\Filament\Resources\Claims\ClaimResource;
use App\Modules\Claims\Filament\Resources\Claims\Pages\ListClaims;
use App\Modules\Claims\Filament\Resources\ClaimTypes\Pages\ListClaimTypes;
use App\Modules\Claims\Models\Claim;
use App\Modules\Claims\Models\ClaimType;
use App\Modules\Claims\Services\ClaimPayment;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ClaimResourceTest extends TestCase
{
    use MakesClaims;
    use RefreshDatabase;

    private Employee $admin;

    private Employee $alice;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ClaimTypeSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->admin = $this->employee('admin@example.com', [], 'admin');
        $this->alice = $this->employee('alice@example.com');
        $this->actingAs($this->admin);
    }

    public function test_the_admins_see_every_claim_but_the_drafts(): void
    {
        $submitted = $this->claim($this->alice, Claim::SUBMITTED);
        $paid = $this->claim($this->alice, Claim::PAID);
        $draft = $this->claim($this->alice);

        Livewire::test(ListClaims::class, ['activeTab' => 'all'])
            ->assertCanSeeTableRecords([$submitted, $paid])
            ->assertCanNotSeeTableRecords([$draft]);

        $this->get(ClaimResource::getUrl('view', ['record' => $submitted]))->assertOk();
        $this->get(ClaimResource::getUrl('view', ['record' => $draft]))->assertNotFound();
    }

    public function test_an_admin_approves_a_claim_then_marks_it_paid(): void
    {
        $claim = $this->claim($this->alice, Claim::SUBMITTED);

        Livewire::test(ListClaims::class)->callTableAction('approve', $claim);
        $this->assertSame(Claim::APPROVED, $claim->fresh()->status);

        Livewire::test(ListClaims::class, ['activeTab' => 'approved'])
            ->callTableAction('markPaid', $claim, ['paid_at' => '2026-11-15', 'payment_reference' => 'TRF-001'])
            ->assertHasNoTableActionErrors();

        $claim->refresh();
        $this->assertSame(Claim::PAID, $claim->status);
        $this->assertSame('2026-11-15', $claim->paid_at->toDateString());
        $this->assertSame('TRF-001', $claim->payment_reference);
        $this->assertSame($this->admin->id, $claim->paid_by);
    }

    public function test_only_an_approved_claim_is_paid_and_only_by_an_admin(): void
    {
        $submitted = $this->claim($this->alice, Claim::SUBMITTED);
        $approved = $this->claim($this->alice, Claim::APPROVED);
        $payment = app(ClaimPayment::class);

        Livewire::test(ListClaims::class)->assertTableActionHidden('markPaid', $submitted);
        $this->assertNotEmpty($payment->markPaid($submitted, $this->admin, now()));
        $this->assertNotEmpty($payment->markPaid($approved, $this->alice, now()));

        $this->assertSame(Claim::SUBMITTED, $submitted->fresh()->status);
        $this->assertSame(Claim::APPROVED, $approved->fresh()->status);
    }

    public function test_an_employee_cannot_open_the_admin_claims(): void
    {
        $this->actingAs($this->alice);

        $this->get(ClaimResource::getUrl('index'))->assertForbidden();
    }

    public function test_an_admin_adds_a_mileage_type(): void
    {
        Livewire::test(ListClaimTypes::class)
            ->callAction('create', ['name' => 'Motorcycle mileage', 'is_mileage' => true, 'rate_per_km' => 0.30, 'is_active' => true, 'sort' => 5])
            ->assertHasNoActionErrors();

        $type = ClaimType::where('name', 'Motorcycle mileage')->sole();
        $this->assertTrue($type->is_mileage);
        $this->assertSame('0.30', $type->rate_per_km);
    }

    public function test_an_admin_sets_which_types_need_a_receipt(): void
    {
        $this->assertTrue(ClaimType::requiresReceipt($this->type('Meals')->id));
        $this->assertFalse(ClaimType::requiresReceipt($this->type('Parking & Toll')->id));
        $parking = $this->type('Parking & Toll');

        Livewire::test(ListClaimTypes::class)
            ->callTableAction('edit', $parking, ['name' => $parking->name, 'requires_receipt' => true])
            ->assertHasNoTableActionErrors();

        $this->assertTrue(ClaimType::requiresReceipt($parking->id));
    }

    public function test_a_type_used_by_a_claim_cannot_be_deleted(): void
    {
        $this->claim($this->alice);

        Livewire::test(ListClaimTypes::class)
            ->assertTableActionHidden('delete', $this->type('Parking & Toll'))
            ->assertTableActionVisible('delete', $this->type('Others'));
    }
}
