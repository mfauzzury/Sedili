<?php

namespace App\Modules\Claims\Tests;

use App\Models\Employee;
use App\Modules\Claims\ClaimsModule;
use App\Modules\Claims\Database\Seeders\ClaimTypeSeeder;
use App\Modules\Claims\Models\Claim;
use App\Modules\Claims\Notifications\ClaimDecided;
use App\Modules\Claims\Notifications\ClaimPaid;
use App\Modules\Claims\Notifications\ClaimSubmitted;
use App\Modules\Claims\Services\ClaimApproval;
use App\Modules\Claims\Services\ClaimPayment;
use App\Modules\Claims\Services\ClaimSubmission;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClaimEmailTest extends TestCase
{
    use MakesClaims;
    use RefreshDatabase;

    private Employee $boss;

    private Employee $alice;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ClaimTypeSeeder::class);

        $this->boss = $this->employee('boss@example.com');
        $this->alice = $this->employee('alice@example.com', ['supervisor_id' => $this->boss->id]);
        $this->admin = $this->employee('admin@example.com', [], 'admin');
    }

    public function test_a_submitted_claim_is_sent_to_the_admins_and_to_the_supervisor(): void
    {
        $claim = $this->claim($this->alice);

        app(ClaimSubmission::class)->submit($claim, $this->alice);

        Notification::assertSentTo($this->admin, ClaimSubmitted::class, fn (ClaimSubmitted $n) => ! $n->asSupervisor);
        Notification::assertSentTo($this->boss, ClaimSubmitted::class, fn (ClaimSubmitted $n) => $n->asSupervisor);
        Notification::assertNotSentTo($this->alice, ClaimSubmitted::class);
    }

    public function test_the_employee_is_told_of_the_decision_and_of_the_payment(): void
    {
        $claim = $this->claim($this->alice, Claim::SUBMITTED);

        app(ClaimApproval::class)->decide($claim, $this->boss, Claim::APPROVED);
        Notification::assertSentTo($this->alice, ClaimDecided::class);

        app(ClaimPayment::class)->markPaid($claim, $this->admin, now(), 'TRF-001');
        Notification::assertSentTo($this->alice, ClaimPaid::class, function (ClaimPaid $n) {
            $mail = $n->toMail($this->alice);

            return str_contains(implode("\n", $mail->introLines), 'TRF-001');
        });
    }

    public function test_the_email_previews_render(): void
    {
        foreach ((new ClaimsModule)->emailPreviews() as $label => $build) {
            $this->assertInstanceOf(MailMessage::class, $build($this->admin), $label);
        }
    }

    public function test_the_to_do_lines_of_the_supervisor_and_of_the_admins(): void
    {
        $module = new ClaimsModule;
        $before = $module->todos($this->boss, 'portal');
        $this->assertSame([], $before);

        $this->claim($this->alice, Claim::SUBMITTED, amount: 30);
        $this->claim($this->alice, Claim::SUBMITTED, amount: 20);
        $this->claim($this->alice, Claim::APPROVED, amount: 12.5);

        $boss = $module->todos($this->boss, 'portal');
        $this->assertCount(1, $boss);
        $this->assertSame('2 claims to approve', $boss[0]->title);

        $admin = $module->todos($this->admin, 'portal');
        $this->assertCount(1, $admin);
        $this->assertSame('1 approved claim to pay', $admin[0]->title);

        $this->assertSame([], $module->todos($this->boss, 'admin'));
    }
}
