<?php

namespace App\Modules\Leave\Tests;

use App\Models\Department;
use App\Models\Employee;
use App\Modules\Leave\Database\Seeders\LeaveTypeSeeder;
use App\Modules\Leave\Filament\Portal\Pages\ListMyLeaves;
use App\Modules\Leave\Filament\Resources\LeaveTypes\Pages\ListLeaveTypes;
use App\Modules\Leave\Filament\Resources\Leaves\Pages\ListLeaves;
use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveApproval;
use App\Modules\Leave\Services\LeaveAttachment;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class LeaveEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private Employee $alice;
    private Employee $boss;
    private Employee $other;
    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(LeaveAttachment::DISK);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(LeaveTypeSeeder::class);

        $this->boss = $this->employee('boss@example.com');
        $this->alice = $this->employee('alice@example.com', ['supervisor_id' => $this->boss->id]);
        $this->other = $this->employee('other@example.com');
        $this->admin = $this->employee('admin@example.com', [], 'admin');
    }

    private function employee(string $email, array $attributes = [], string $role = 'employee'): Employee
    {
        $employee = new Employee();
        $employee->forceFill([
            'first_name' => ucfirst(strtok($email, '@')),
            'last_name' => 'Test',
            'email' => $email,
            'password' => 'password',
            'employment_type' => 'Permanent',
            'hire_date' => '2018-01-01',
            ...$attributes,
        ])->save();
        $employee->assignRole($role);
        return $employee;
    }

    private function pdf(string $name = 'certificate.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, 'application/pdf');
    }

    /** A leave with a document already stored, as the upload field would save it. */
    private function leaveWithDocument(string $type = 'Sick Leave'): Leave
    {
        $path = LeaveAttachment::DIRECTORY . '/' . bin2hex(random_bytes(8)) . '.pdf';
        Storage::disk(LeaveAttachment::DISK)->put($path, 'the certificate');

        return Leave::create([
            'employee_id' => $this->alice->id,
            'leave_type' => $type,
            'start_date' => '2026-12-07',
            'end_date' => '2026-12-08',
            'status' => 'Pending',
            'attachment' => $path,
        ]);
    }

    private function request(array $data)
    {
        Filament::setCurrentPanel('portal');
        $this->actingAs($this->alice->fresh());

        return Livewire::test(ListMyLeaves::class)->callAction(CreateAction::class, array_merge([
            'leave_type' => 'Sick Leave',
            'start_date' => '2026-12-07',
            'end_date' => '2026-12-08',
        ], $data));
    }

    // --- Leave types

    public function test_sick_and_hospitalisation_leave_require_evidence_by_default_but_annual_leave_does_not(): void
    {
        $this->assertTrue(LeaveType::requiresEvidence('Sick Leave'));
        $this->assertTrue(LeaveType::requiresEvidence('Hospitalisation Leave'));
        $this->assertFalse(LeaveType::requiresEvidence('Annual Leave'));
        $this->assertFalse(LeaveType::requiresEvidence('Unknown type'));
        $this->assertFalse(LeaveType::requiresEvidence(null));
    }

    public function test_seeding_again_keeps_the_choice_of_an_admin(): void
    {
        LeaveType::where('name', 'Sick Leave')->update(['requires_attachment' => false]);
        LeaveType::where('name', 'Annual Leave')->update(['requires_attachment' => true]);

        $this->seed(LeaveTypeSeeder::class);

        $this->assertFalse(LeaveType::requiresEvidence('Sick Leave'));
        $this->assertTrue(LeaveType::requiresEvidence('Annual Leave'));
    }

    public function test_an_admin_sets_which_leave_types_require_evidence(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin->fresh());
        $type = LeaveType::where('name', 'Compassionate Leave')->first();

        Livewire::test(ListLeaveTypes::class)
            ->callTableAction('edit', $type, ['name' => $type->name, 'requires_attachment' => true])
            ->assertHasNoTableActionErrors();

        $this->assertTrue(LeaveType::requiresEvidence('Compassionate Leave'));
    }

    // --- Requesting leave

    public function test_a_request_without_the_required_evidence_is_refused(): void
    {
        $this->request([])->assertHasActionErrors(['attachment' => 'required']);

        $this->assertSame(0, Leave::count());
    }

    public function test_a_request_with_the_evidence_is_saved_with_the_document_on_the_private_disk(): void
    {
        $this->request(['attachment' => $this->pdf()])->assertHasNoActionErrors();

        $leave = Leave::firstOrFail();
        $this->assertSame($this->alice->id, $leave->employee_id);
        $this->assertTrue(LeaveAttachment::isValidPath($leave->attachment));
        Storage::disk(LeaveAttachment::DISK)->assertExists($leave->attachment);
    }

    public function test_the_evidence_is_optional_for_the_other_leave_types(): void
    {
        $this->request(['leave_type' => 'Annual Leave'])->assertHasNoActionErrors();

        $this->assertNull(Leave::firstOrFail()->attachment);
    }

    public function test_only_pdf_and_images_are_accepted(): void
    {
        $this->request(['attachment' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])
            ->assertHasActionErrors(['attachment']);
        $this->request(['attachment' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')])
            ->assertHasActionErrors(['attachment']);

        $this->assertSame(0, Leave::count());
    }

    public function test_an_admin_must_attach_the_evidence_when_creating_a_request_but_not_for_a_rejection(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin->fresh());
        $data = [
            'employee_id' => $this->alice->id,
            'leave_type' => 'Sick Leave',
            'start_date' => '2026-12-07',
            'end_date' => '2026-12-08',
            'status' => 'Approved',
        ];

        Livewire::test(ListLeaves::class)
            ->callAction(CreateAction::class, $data)
            ->assertHasActionErrors(['attachment' => 'required']);

        Livewire::test(ListLeaves::class)
            ->callAction(CreateAction::class, ['status' => 'Rejected'] + $data)
            ->assertHasNoActionErrors();
    }

    public function test_the_employee_can_attach_the_evidence_after_requesting(): void
    {
        $leave = Leave::create([
            'employee_id' => $this->alice->id, 'leave_type' => 'Sick Leave',
            'start_date' => '2026-12-07', 'end_date' => '2026-12-08', 'status' => 'Pending',
        ]);
        Filament::setCurrentPanel('portal');
        $this->actingAs($this->alice->fresh());

        // The document is required in this form
        Livewire::test(ListMyLeaves::class)
            ->callTableAction('attachEvidence', $leave, [])
            ->assertHasTableActionErrors(['attachment' => 'required']);
        $this->assertNull($leave->fresh()->attachment);

        // The supervisor cannot approve before
        $this->assertNotEmpty(app(LeaveApproval::class)->decide($leave, $this->boss->fresh(), 'Approved'));

        Livewire::test(ListMyLeaves::class)
            ->callTableAction('attachEvidence', $leave, ['attachment' => $this->pdf()])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Evidence attached');

        $leave->refresh();
        Storage::disk(LeaveAttachment::DISK)->assertExists($leave->attachment);
        // ... and can after
        $this->assertSame([], app(LeaveApproval::class)->decide($leave, $this->boss->fresh(), 'Approved'));
    }

    public function test_the_attach_button_is_only_for_requests_without_a_document_that_are_not_rejected(): void
    {
        $without = Leave::create([
            'employee_id' => $this->alice->id, 'leave_type' => 'Sick Leave',
            'start_date' => '2026-12-07', 'end_date' => '2026-12-08', 'status' => 'Approved',
        ]);
        $rejected = Leave::create([
            'employee_id' => $this->alice->id, 'leave_type' => 'Sick Leave',
            'start_date' => '2026-12-14', 'end_date' => '2026-12-15', 'status' => 'Rejected',
        ]);
        $with = $this->leaveWithDocument();
        Filament::setCurrentPanel('portal');
        $this->actingAs($this->alice->fresh());

        Livewire::test(ListMyLeaves::class)
            ->assertTableActionVisible('attachEvidence', $without)
            ->assertTableActionHidden('attachEvidence', $rejected)
            ->assertTableActionHidden('attachEvidence', $with);

        // Nobody can add a document to the request of somebody else
        $this->actingAs($this->other->fresh());
        $this->assertFalse(\App\Modules\Leave\Filament\Portal\MyLeaveResource::canAttachEvidence($without));
    }

    // --- Downloading

    public function test_the_employee_their_supervisor_and_the_admins_can_open_the_document(): void
    {
        $leave = $this->leaveWithDocument();
        $url = LeaveAttachment::url($leave);

        foreach ([$this->alice, $this->boss, $this->admin] as $user) {
            $this->actingAs($user->fresh())->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        }
        $this->assertSame('the certificate', $this->actingAs($this->alice->fresh())->get($url)->streamedContent());
    }

    public function test_the_manager_of_the_department_can_open_it_too(): void
    {
        $manager = $this->employee('manager@example.com');
        $department = Department::create(['name' => 'Sales', 'manager_id' => $manager->id]);
        $this->alice->update(['supervisor_id' => null, 'department_id' => $department->id]);
        $leave = $this->leaveWithDocument();

        $this->actingAs($manager->fresh())->get(LeaveAttachment::url($leave))->assertOk();
    }

    public function test_anybody_else_and_guests_cannot_open_the_document(): void
    {
        $leave = $this->leaveWithDocument();
        $url = LeaveAttachment::url($leave);

        $this->actingAs($this->other->fresh())->get($url)->assertForbidden();
        // Alice does not supervise the boss
        $this->actingAs($this->alice->fresh());
        $bossLeave = Leave::create([
            'employee_id' => $this->boss->id, 'leave_type' => 'Annual Leave',
            'start_date' => '2026-12-14', 'end_date' => '2026-12-15', 'status' => 'Pending',
            'attachment' => $leave->attachment,
        ]);
        $this->get(LeaveAttachment::url($bossLeave))->assertForbidden();

        auth()->logout();
        $this->get($url)->assertForbidden();
    }

    public function test_a_missing_file_is_a_404_for_those_allowed_and_a_403_for_the_others(): void
    {
        $leave = $this->leaveWithDocument();
        Storage::disk(LeaveAttachment::DISK)->delete($leave->attachment);

        $this->actingAs($this->alice->fresh())->get(LeaveAttachment::url($leave))->assertNotFound();
        $this->actingAs($this->other->fresh())->get(LeaveAttachment::url($leave))->assertForbidden();
    }

    public function test_the_document_is_not_reachable_from_the_web_server(): void
    {
        $leave = $this->leaveWithDocument();

        $this->assertFileDoesNotExist(public_path($leave->attachment));
        $this->assertFileDoesNotExist(public_path('storage/' . $leave->attachment));
        $this->assertSame('local', LeaveAttachment::DISK);
    }

    // --- Files

    public function test_a_path_that_does_not_come_from_the_upload_field_is_refused(): void
    {
        foreach (['../../.env', '/etc/passwd', 'leave-attachments/../../.env', 'other/file.pdf'] as $path) {
            try {
                Leave::create([
                    'employee_id' => $this->alice->id, 'leave_type' => 'Annual Leave',
                    'start_date' => '2026-12-07', 'end_date' => '2026-12-08', 'status' => 'Pending',
                    'attachment' => $path,
                ]);
                $this->fail("The path [$path] was accepted");
            } catch (ValidationException) {
                $this->assertSame(0, Leave::count());
            }
        }
    }

    public function test_deleting_a_request_deletes_its_document(): void
    {
        $leave = $this->leaveWithDocument();
        $path = $leave->attachment;

        $leave->delete();

        Storage::disk(LeaveAttachment::DISK)->assertMissing($path);
    }

    public function test_replacing_the_document_deletes_the_previous_one(): void
    {
        $leave = $this->leaveWithDocument();
        $old = $leave->attachment;
        $new = LeaveAttachment::DIRECTORY . '/replacement.pdf';
        Storage::disk(LeaveAttachment::DISK)->put($new, 'new');

        $leave->update(['attachment' => $new]);

        Storage::disk(LeaveAttachment::DISK)->assertMissing($old);
        Storage::disk(LeaveAttachment::DISK)->assertExists($new);
    }

    // --- Approval

    public function test_a_supervisor_cannot_approve_a_request_whose_required_evidence_is_missing(): void
    {
        $leave = Leave::create([
            'employee_id' => $this->alice->id, 'leave_type' => 'Sick Leave',
            'start_date' => '2026-12-07', 'end_date' => '2026-12-08', 'status' => 'Pending',
        ]);
        $service = app(LeaveApproval::class);

        $errors = $service->decide($leave, $this->boss->fresh(), 'Approved');

        $this->assertStringContainsString('evidence', implode(' ', $errors));
        $this->assertSame('Pending', $leave->fresh()->status);

        // Rejecting never needs it, and a request with the document can be approved
        $this->assertSame([], $service->decide($leave, $this->boss->fresh(), 'Rejected', 'No certificate'));
        $with = $this->leaveWithDocument();
        $this->assertSame([], $service->decide($with, $this->boss->fresh(), 'Approved'));
    }
}
