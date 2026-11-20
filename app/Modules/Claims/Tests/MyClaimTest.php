<?php

namespace App\Modules\Claims\Tests;

use App\Models\Employee;
use App\Modules\Claims\Database\Seeders\ClaimTypeSeeder;
use App\Modules\Claims\Filament\Portal\MyClaimResource;
use App\Modules\Claims\Filament\Portal\Pages\CreateMyClaim;
use App\Modules\Claims\Filament\Portal\Pages\ListMyClaims;
use App\Modules\Claims\Models\Claim;
use App\Modules\Claims\Models\ClaimItem;
use App\Modules\Claims\Services\ClaimReceipt;
use App\Modules\Claims\Services\ClaimSubmission;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MyClaimTest extends TestCase
{
    use MakesClaims;
    use RefreshDatabase;

    private Employee $alice;

    private Employee $bob;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake(ClaimReceipt::DISK);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ClaimTypeSeeder::class);
        Filament::setCurrentPanel('portal');

        $this->alice = $this->employee('alice@example.com');
        $this->bob = $this->employee('bob@example.com');
        $this->actingAs($this->alice);
    }

    /** A new claim: a Meals item (receipt required) and a Mileage item (no receipt needed). */
    private function fillNewClaim(bool $withReceipt = true): \Livewire\Features\SupportTesting\Testable
    {
        $undoRepeaterFake = Repeater::fake();
        $this->beforeApplicationDestroyed($undoRepeaterFake);

        return Livewire::test(CreateMyClaim::class)->fillForm([
            'title' => 'Client visit to Penang',
            // Ignored: the employee and the status are set on the server
            'employee_id' => $this->bob->id,
            'status' => Claim::APPROVED,
            'items' => [
                [
                    'claim_type_id' => $this->type('Meals')->id,
                    'date' => '2026-11-10',
                    'description' => 'Lunch with the client',
                    'amount' => 42.5,
                    'receipt' => $withReceipt ? UploadedFile::fake()->create('lunch.pdf', 100, 'application/pdf') : null,
                ],
                [
                    'claim_type_id' => $this->type('Mileage')->id,
                    'date' => '2026-11-10',
                    'description' => 'Office to client',
                    'distance_km' => 25,
                ],
            ],
        ]);
    }

    public function test_an_employee_only_sees_their_own_claims(): void
    {
        $mine = $this->claim($this->alice);
        $theirs = $this->claim($this->bob);

        Livewire::test(ListMyClaims::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_a_new_claim_is_a_draft_of_the_employee_with_its_items_and_total(): void
    {
        $this->fillNewClaim()->call('create')->assertHasNoFormErrors();

        $claim = Claim::sole();
        $this->assertSame($this->alice->id, $claim->employee_id);
        $this->assertSame(Claim::DRAFT, $claim->status);
        $this->assertSame(2, $claim->items()->count());
        // 42.50 + 25 km x 0.60
        $this->assertSame('57.50', $claim->total);

        $receipt = $claim->items()->whereNotNull('receipt')->sole()->receipt;
        $this->assertStringStartsWith(ClaimReceipt::DIRECTORY.'/', $receipt);
        Storage::disk(ClaimReceipt::DISK)->assertExists($receipt);
    }

    public function test_the_receipt_is_required_only_for_the_types_that_need_one(): void
    {
        // Meals needs one; Mileage, the second item, does not
        $this->fillNewClaim(withReceipt: false)
            ->call('create')
            ->assertHasFormErrors(['items.0.receipt' => 'required'])
            ->assertHasNoFormErrors(['items.1.receipt']);

        $this->assertSame(0, Claim::count());
    }

    public function test_a_claim_is_not_submitted_while_a_required_receipt_is_missing(): void
    {
        // A Meals item saved without its receipt (for example before an admin made it required)
        $claim = $this->claim($this->alice, type: 'Meals');

        $errors = app(ClaimSubmission::class)->submit($claim, $this->alice);

        $this->assertSame(['Attach the receipt of "Lunch with the client" (Meals).'], $errors);
        $this->assertSame(Claim::DRAFT, $claim->fresh()->status);

        $claim->items()->first()->update(['receipt' => $this->storedReceipt()]);
        $this->assertSame([], app(ClaimSubmission::class)->submit($claim, $this->alice));
        $this->assertSame(Claim::SUBMITTED, $claim->fresh()->status);
    }

    public function test_save_and_submit_submits_the_new_claim(): void
    {
        $this->fillNewClaim()
            ->callAction(TestAction::make('createAndSubmit')->schemaComponent('form-actions', schema: 'content'))
            ->assertHasNoFormErrors();

        $claim = Claim::sole();
        $this->assertSame(Claim::SUBMITTED, $claim->status);
        $this->assertNotNull($claim->submitted_at);
    }

    public function test_an_employee_submits_and_withdraws_a_draft(): void
    {
        $claim = $this->claim($this->alice);

        Livewire::test(ListMyClaims::class)->callTableAction('submit', $claim);
        $this->assertSame(Claim::SUBMITTED, $claim->fresh()->status);

        Livewire::test(ListMyClaims::class)->callTableAction('withdraw', $claim);
        $this->assertSame(Claim::DRAFT, $claim->fresh()->status);
        $this->assertNull($claim->fresh()->submitted_at);
    }

    public function test_a_claim_without_items_cannot_be_submitted(): void
    {
        $claim = Claim::create(['employee_id' => $this->alice->id, 'title' => 'Empty']);

        $errors = app(ClaimSubmission::class)->submit($claim, $this->alice);

        $this->assertNotEmpty($errors);
        $this->assertSame(Claim::DRAFT, $claim->fresh()->status);
    }

    public function test_nobody_submits_withdraws_or_deletes_the_claim_of_somebody_else(): void
    {
        $draft = $this->claim($this->bob);
        $submitted = $this->claim($this->bob, Claim::SUBMITTED);
        $service = app(ClaimSubmission::class);

        $this->assertNotEmpty($service->submit($draft, $this->alice));
        $this->assertNotEmpty($service->delete($draft, $this->alice));
        $this->assertNotEmpty($service->withdraw($submitted, $this->alice));

        $this->assertSame(Claim::DRAFT, $draft->fresh()->status);
        $this->assertSame(Claim::SUBMITTED, $submitted->fresh()->status);
    }

    public function test_only_a_draft_can_be_edited(): void
    {
        $draft = $this->claim($this->alice);
        $submitted = $this->claim($this->alice, Claim::SUBMITTED);

        $this->get(MyClaimResource::getUrl('edit', ['record' => $draft]))->assertOk();
        $this->get(MyClaimResource::getUrl('edit', ['record' => $submitted]))->assertForbidden();
        $this->get(MyClaimResource::getUrl('view', ['record' => $submitted]))->assertOk();
    }

    public function test_the_claim_of_somebody_else_cannot_be_opened(): void
    {
        $theirs = $this->claim($this->bob);

        $this->get(MyClaimResource::getUrl('view', ['record' => $theirs]))->assertNotFound();
        $this->get(MyClaimResource::getUrl('edit', ['record' => $theirs]))->assertNotFound();
    }

    public function test_deleting_a_draft_removes_its_receipts(): void
    {
        $path = $this->storedReceipt();
        $claim = $this->claim($this->alice, receipt: $path);

        Livewire::test(ListMyClaims::class)->callTableAction('deleteDraft', $claim);

        $this->assertSame(0, Claim::count());
        $this->assertSame(0, ClaimItem::count());
        Storage::disk(ClaimReceipt::DISK)->assertMissing($path);
    }

    public function test_a_submitted_claim_cannot_be_deleted(): void
    {
        $claim = $this->claim($this->alice, Claim::SUBMITTED);

        Livewire::test(ListMyClaims::class)->assertTableActionHidden('deleteDraft', $claim);
        $this->assertNotEmpty(app(ClaimSubmission::class)->delete($claim, $this->alice));
        $this->assertSame(1, Claim::count());
    }
}
