<?php

namespace App\Modules\Leave\ChatTools;

use App\Models\Employee;
use App\Modules\ChatTool;
use App\Modules\Leave\Services\LeaveBalanceSummary;

/**
 * "How many leave days do I have left?": the balance of the employee who is chatting.
 */
class LeaveBalanceTool extends ChatTool
{
    public function key(): string
    {
        return 'balance';
    }

    public function label(): string
    {
        return 'Leave balance';
    }

    public function description(): string
    {
        return 'Returns the leave balance of the employee who is asking, for one calendar year (this year unless a year is given): for each leave type they can take, the days they are entitled to, the days already taken (approved), the days waiting for approval (pending) and the days left. Use it for questions such as "how many leave days do I have left?". It cannot look up anyone else.';
    }

    public function dataSent(): string
    {
        return "The employee's leave entitlement, days taken, pending and left, per leave type.";
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'year' => ['type' => 'integer', 'description' => 'Calendar year, for example 2026. Leave out for the current year.'],
            ],
        ];
    }

    public function run(Employee $employee, array $input): array
    {
        $year = $this->year($input['year'] ?? null);

        $balances = array_map(fn (array $row): array => [
            'leave_type' => $row['type']->name,
            'entitled_days' => $row['entitlement'],
            'taken_days' => $row['approved'],
            'pending_days' => $row['pending'],
            'days_left' => $row['left'],
        ], app(LeaveBalanceSummary::class)->for($employee, $year));

        return [
            'year' => $year,
            'balances' => $balances,
            'note' => $balances === [] ? 'No leave type with a limit applies to this employee.' : 'Leave types without a limit are not listed.',
        ];
    }

    /** The year asked for, within a year of today; anything else means the current year */
    private function year(mixed $value): int
    {
        $current = now()->year;
        $year = is_numeric($value) ? (int) $value : $current;

        return abs($year - $current) <= 1 ? $year : $current;
    }
}
