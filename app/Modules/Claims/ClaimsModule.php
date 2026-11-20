<?php

namespace App\Modules\Claims;

use App\Models\Employee;
use App\Modules\Claims\Database\Seeders\ClaimTypeSeeder;
use App\Modules\Claims\Filament\Portal\MyClaimResource;
use App\Modules\Claims\Filament\Portal\TeamClaimResource;
use App\Modules\Claims\Filament\Resources\Claims\ClaimResource;
use App\Modules\Claims\Filament\Resources\ClaimTypes\ClaimTypeResource;
use App\Modules\Claims\Http\ClaimReceiptController;
use App\Modules\Claims\Models\Claim;
use App\Modules\Claims\Models\ClaimItem;
use App\Modules\Claims\Notifications\ClaimDecided;
use App\Modules\Claims\Notifications\ClaimPaid;
use App\Modules\Claims\Notifications\ClaimSubmitted;
use App\Modules\Claims\Observers\ClaimItemObserver;
use App\Modules\Claims\Observers\ClaimObserver;
use App\Modules\Claims\Reports\ClaimsByEmployeeReport;
use App\Modules\Claims\Reports\ClaimsByTypeReport;
use App\Modules\Module;
use App\Modules\Todo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

class ClaimsModule extends Module
{
    public function key(): string
    {
        return 'claims';
    }

    public function label(): string
    {
        return 'Claims';
    }

    public function description(): string
    {
        return 'Expense claims with receipts and mileage: employees claim in the portal, their supervisor approves, HR marks them as paid.';
    }

    public function filament(string $panelId): array
    {
        return match ($panelId) {
            'admin' => [
                'resources' => [ClaimResource::class, ClaimTypeResource::class],
            ],
            'portal' => [
                'resources' => [MyClaimResource::class, TeamClaimResource::class],
            ],
            default => [],
        };
    }

    /**
     * A supervisor: the claims of their team waiting for a decision. An admin: the approved claims
     * waiting to be paid.
     */
    public function todos(Employee $employee, string $panelId): array
    {
        if ($panelId !== 'portal') {
            return [];
        }

        $todos = [];

        $toApprove = Claim::query()
            ->toBase()
            ->where('status', Claim::SUBMITTED)
            ->whereIn('employee_id', Employee::query()->reportingTo($employee)->select('employees.id'))
            ->selectRaw('count(*) as count, sum(total) as total, min(submitted_at) as oldest')
            ->first();
        $count = (int) ($toApprove->count ?? 0);
        if ($count > 0) {
            $todos[] = new Todo(
                title: $count === 1 ? '1 claim to approve' : "{$count} claims to approve",
                url: TeamClaimResource::getUrl('index', panel: 'portal'),
                detail: Claim::formatAmount($toApprove->total).' in total',
                badge: $toApprove->oldest ? 'Since '.Carbon::parse($toApprove->oldest)->format('j M') : null,
                color: 'warning',
                icon: 'heroicon-o-receipt-percent',
            );
        }

        if ($employee->hasRole('admin')) {
            $toPay = Claim::query()
                ->toBase()
                ->where('status', Claim::APPROVED)
                ->selectRaw('count(*) as count, sum(total) as total')
                ->first();
            $count = (int) ($toPay->count ?? 0);
            if ($count > 0) {
                $todos[] = new Todo(
                    title: $count === 1 ? '1 approved claim to pay' : "{$count} approved claims to pay",
                    url: ClaimResource::getUrl('index', panel: 'admin'),
                    detail: Claim::formatAmount($toPay->total).' in total',
                    icon: 'heroicon-o-banknotes',
                );
            }
        }

        return $todos;
    }

    public function emailPreviews(): array
    {
        // Sample claim, never saved: the admin who previews is the employee of the claim
        $sample = function (Employee $employee, string $status): Claim {
            $claim = new Claim([
                'employee_id' => $employee->id,
                'title' => 'Client visit to Penang',
                'status' => $status,
                'total' => 186.40,
                'notes' => 'Meeting with the client on the new project.',
                'actioned_by' => in_array($status, [Claim::APPROVED, Claim::REJECTED, Claim::PAID], true) ? $employee->id : null,
                'rejection_reason' => $status === Claim::REJECTED ? 'Please attach the receipt of the hotel.' : null,
                'paid_at' => $status === Claim::PAID ? now() : null,
                'payment_reference' => $status === Claim::PAID ? 'TRF-20261117-001' : null,
            ]);
            $items = (new ClaimItem)->newCollection([new ClaimItem, new ClaimItem, new ClaimItem]);

            return $claim->setRelation('employee', $employee)->setRelation('items', $items);
        };

        return [
            'Claim submitted' => fn (Employee $to) => (new ClaimSubmitted($sample($to, Claim::SUBMITTED)))->toMail($to),
            'Claim submitted (email to the supervisor)' => fn (Employee $to) => (new ClaimSubmitted($sample($to, Claim::SUBMITTED), asSupervisor: true))->toMail($to),
            'Claim approved' => fn (Employee $to) => (new ClaimDecided($sample($to, Claim::APPROVED)))->toMail($to),
            'Claim rejected' => fn (Employee $to) => (new ClaimDecided($sample($to, Claim::REJECTED)))->toMail($to),
            'Claim paid' => fn (Employee $to) => (new ClaimPaid($sample($to, Claim::PAID)))->toMail($to),
        ];
    }

    public function boot(): void
    {
        Claim::observe(ClaimObserver::class);
        ClaimItem::observe(ClaimItemObserver::class);

        // Receipts of the claim items; the controller checks who may open them (no "auth"
        // middleware: it would redirect a guest to a login route that does not exist)
        Route::middleware('web')
            ->get('claim-receipts/{claimItem}', ClaimReceiptController::class)
            ->name('claims.receipt');
    }

    public function seeders(): array
    {
        return [ClaimTypeSeeder::class];
    }

    public function reports(): array
    {
        return [new ClaimsByTypeReport, new ClaimsByEmployeeReport];
    }
}
