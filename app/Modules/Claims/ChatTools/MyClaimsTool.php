<?php

namespace App\Modules\Claims\ChatTools;

use App\Models\Employee;
use App\Modules\ChatTool;
use App\Modules\Claims\Models\Claim;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * "What happened to my claim?": the claims of the employee who is chatting, and what they are owed.
 */
class MyClaimsTool extends ChatTool
{
    private const LIMIT = 15;

    private const ITEMS_PER_CLAIM = 10;

    public function key(): string
    {
        return 'status';
    }

    public function label(): string
    {
        return 'My claims';
    }

    public function description(): string
    {
        return 'Returns the expense claims of the employee who is asking (up to '.self::LIMIT.', newest first): title, status (Draft, Submitted = waiting for approval, Approved = approved but not paid yet, Rejected with the reason, or Paid with the date and payment reference), total, dates and the items (type, date, description, amount). Also the amounts waiting for approval, approved and waiting for payment, and paid this year. Optionally only one status or one calendar year. Use it for questions such as "was my claim approved?" or "when will I be paid?". It cannot look up anyone else.';
    }

    public function dataSent(): string
    {
        return "The employee's claims: title, status, amounts, dates, items (type, date, description, amount) and the reason of a rejection.";
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => Claim::STATUSES, 'description' => 'Only claims with this status. Leave out for all.'],
                'year' => ['type' => 'integer', 'description' => 'Only claims created in this calendar year. Leave out for all.'],
            ],
        ];
    }

    public function run(Employee $employee, array $input): array
    {
        $status = $this->optionalChoice($input, 'status', Claim::STATUSES);
        $year = $this->optionalYear($input);

        $claims = Claim::query()
            ->with('items.type')
            ->where('employee_id', $employee->id)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($year !== null, fn ($query) => $query->whereYear('created_at', $year))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $own = fn (string $status) => Claim::query()->where('employee_id', $employee->id)->where('status', $status);

        return [
            'currency' => Claim::CURRENCY,
            'amounts' => [
                'waiting_for_approval' => round((float) $own(Claim::SUBMITTED)->sum('total'), 2),
                'approved_waiting_for_payment' => round((float) $own(Claim::APPROVED)->sum('total'), 2),
                'paid_this_year' => round((float) $own(Claim::PAID)->whereYear('paid_at', now()->year)->sum('total'), 2),
            ],
            'claims' => $claims->map(fn (Claim $claim): array => Arr::whereNotNull([
                'title' => $claim->title,
                'status' => $claim->status,
                'total' => (float) $claim->total,
                'submitted_on' => $claim->submitted_at?->toDateString(),
                'decided_on' => $claim->actioned_at?->toDateString(),
                'rejection_reason' => $claim->status === Claim::REJECTED && $claim->rejection_reason !== null ? Str::limit($claim->rejection_reason, 200) : null,
                'paid_on' => $claim->status === Claim::PAID ? $claim->paid_at?->toDateString() : null,
                'payment_reference' => $claim->status === Claim::PAID ? $claim->payment_reference : null,
                'items' => $claim->items->take(self::ITEMS_PER_CLAIM)->map(fn ($item): array => Arr::whereNotNull([
                    'type' => $item->type?->name,
                    'date' => $item->date->toDateString(),
                    'description' => Str::limit((string) $item->description, 120),
                    'amount' => (float) $item->amount,
                    'distance_km' => $item->distance_km !== null ? (float) $item->distance_km : null,
                ]))->values()->all(),
            ]))->all(),
        ];
    }
}
