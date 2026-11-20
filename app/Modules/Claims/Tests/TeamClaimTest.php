<?php

namespace App\Modules\Claims\Tests;

use App\Models\Department;
use App\Models\Employee;
use App\Modules\Claims\Database\Seeders\ClaimTypeSeeder;
use App\Modules\Claims\Filament\Portal\Pages\ListTeamClaims;
use App\Modules\Claims\Filament\Portal\TeamClaimResource;
use App\Modules\Claims\Models\Claim;
use App\Modules\Claims\Services\ClaimApproval;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class TeamClaimTest extends TestCase
{
    use MakesClaims;
    use RefreshDatabase;

    private Employee $boss;

    private Employee $alice;   // chose boss as supervisor

    private Employee $bob;     // in a department managed by boss

    private Employee $carol;   // reports to nobody

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ClaimTypeSeeder::class);
        Filament::setCurrentPanel('portal');

        $this->boss = $this->employee('boss@example.com');
        $department = Department::create(['name' => 'Sales', 'manager_id' => $this->boss->id]);
        $this->alice = $this->employee('alice@example.com', ['supervisor_id' => $this->boss->id]);
        $this->bob = $this->employee('bob@example.com', ['department_id' => $department->id]);
        $this->carol = $this->employee('carol@example.com');
    }

    public function test_a_supervisor_sees_the_submitted_claims_of_their_team_only(): void
    {
        $alice = $this->claim($this->alice, Claim::SUBMITTED);
        $bob = $this->claim($this->bob, Claim::SUBMITTED);
        $aliceDraft = $this->claim($this->alice);
        $carol = $this->claim($this->carol, Claim::SUBMITTED);
        $own = $this->claim($this->boss, Claim::SUBMITTED);

        $this->actingAs($this->boss);

        Livewire::test(ListTeamClaims::class)
            ->assertCanSeeTableRecords([$alice, $bob])
            ->assertCanNotSeeTableRecords([$aliceDraft, $carol, $own]);
    }

    public function test_team_claims_are_only_offered_to_employees_with_a_team(): void
    {
        $this->actingAs($this->carol);
        $this->assertFalse(TeamClaimResource::canViewAny());

        $this->actingAs($this->boss);
        $this->assertTrue(TeamClaimResource::canViewAny());
    }

    public function test_a_supervisor_approves_a_claim_of_their_team(): void
    {
        $claim = $this->claim($this->alice, Claim::SUBMITTED);
        $this->actingAs($this->boss);

        Livewire::test(ListTeamClaims::class)->callTableAction('approve', $claim);

        $claim->refresh();
        $this->assertSame(Claim::APPROVED, $claim->status);
        $this->assertSame($this->boss->id, $claim->actioned_by);
        $this->assertNotNull($claim->actioned_at);
    }

    public function test_a_rejection_needs_a_reason(): void
    {
        $claim = $this->claim($this->bob, Claim::SUBMITTED);
        $this->actingAs($this->boss);

        Livewire::test(ListTeamClaims::class)
            ->callTableAction('reject', $claim, ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);
        $this->assertSame(Claim::SUBMITTED, $claim->fresh()->status);

        Livewire::test(ListTeamClaims::class)
            ->callTableAction('reject', $claim, ['reason' => 'Attach the receipts.']);
        $this->assertSame(Claim::REJECTED, $claim->fresh()->status);
        $this->assertSame('Attach the receipts.', $claim->fresh()->rejection_reason);
    }

    public function test_nobody_decides_their_own_claim_a_claim_outside_their_team_or_a_claim_that_waits_for_nothing(): void
    {
        $approval = app(ClaimApproval::class);

        $this->assertFalse($approval->canDecide($this->boss, $this->claim($this->boss, Claim::SUBMITTED)));
        $this->assertFalse($approval->canDecide($this->boss, $this->claim($this->carol, Claim::SUBMITTED)));
        $this->assertFalse($approval->canDecide($this->boss, $this->claim($this->alice)));
        $this->assertFalse($approval->canDecide($this->boss, $this->claim($this->alice, Claim::APPROVED)));
        $this->assertFalse($approval->canDecide($this->alice, $this->claim($this->bob, Claim::SUBMITTED)));

        $outside = $this->claim($this->carol, Claim::SUBMITTED);
        $this->assertNotEmpty($approval->decide($outside, $this->boss, Claim::APPROVED));
        $this->assertSame(Claim::SUBMITTED, $outside->fresh()->status);
    }

    public function test_an_admin_decides_any_claim_but_their_own(): void
    {
        $admin = $this->employee('admin@example.com', [], 'admin');
        $approval = app(ClaimApproval::class);

        $this->assertTrue($approval->canDecide($admin, $this->claim($this->carol, Claim::SUBMITTED)));
        $this->assertFalse($approval->canDecide($admin, $this->claim($admin, Claim::SUBMITTED)));
    }

    public function test_a_claim_withdrawn_in_the_meantime_is_not_decided(): void
    {
        $claim = $this->claim($this->alice, Claim::SUBMITTED);
        $stale = $claim->fresh();
        $claim->update(['status' => Claim::DRAFT]);

        $this->assertNotEmpty(app(ClaimApproval::class)->decide($stale, $this->boss, Claim::APPROVED));
        $this->assertSame(Claim::DRAFT, $claim->fresh()->status);
    }
}
