<?php

namespace App\Modules\Leave\ChatTools;

use App\Models\Employee;
use App\Modules\ChatTool;
use App\Modules\Leave\Models\Leave;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * "What happened to my leave request?": the latest leave requests of the employee who is chatting.
 */
class MyLeaveRequestsTool extends ChatTool
{
    private const LIMIT = 15;

    private const STATUSES = ['Pending', 'Approved', 'Rejected'];

    public function key(): string
    {
        return 'requests';
    }

    public function label(): string
    {
        return 'My leave requests';
    }

    public function description(): string
    {
        return 'Returns the latest leave requests of the employee who is asking (up to '.self::LIMIT.', newest first): leave type, dates, days, status (Pending, Approved or Rejected) and, when rejected, the reason. Optionally only one status or one calendar year. Use it for questions such as "was my leave approved?" or "when is my next leave?". It cannot look up anyone else.';
    }

    public function dataSent(): string
    {
        return "The employee's recent leave requests: type, dates, days, status and the reason of a rejection.";
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => self::STATUSES, 'description' => 'Only requests with this status. Leave out for all.'],
                'year' => ['type' => 'integer', 'description' => 'Only requests starting in this calendar year. Leave out for all.'],
            ],
        ];
    }

    public function run(Employee $employee, array $input): array
    {
        $status = $this->optionalChoice($input, 'status', self::STATUSES);
        $year = $this->optionalYear($input);

        $requests = Leave::query()
            ->where('employee_id', $employee->id)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($year !== null, fn ($query) => $query->whereYear('start_date', $year))
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return [
            'requests' => $requests->map(fn (Leave $leave): array => Arr::whereNotNull([
                'leave_type' => $leave->leave_type,
                'start_date' => $leave->start_date?->toDateString(),
                'end_date' => $leave->end_date?->toDateString(),
                'days' => $leave->days,
                'status' => $leave->status,
                'rejection_reason' => $leave->status === 'Rejected' && $leave->rejection_reason !== null ? Str::limit($leave->rejection_reason, 200) : null,
            ]))->all(),
        ];
    }
}
