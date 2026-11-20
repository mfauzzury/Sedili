<?php

namespace App\Modules\Chatbox\ChatTools;

use App\Models\Employee;
use App\Modules\ChatTool;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * "Who is my supervisor?": the work details of the employee who is chatting. Only what an employee
 * would say about their job. Never the national ID, the contact and family details, the salary of the
 * position or anything else of the employee record.
 */
class ProfileTool extends ChatTool
{
    public function key(): string
    {
        return 'profile';
    }

    public function label(): string
    {
        return 'My work profile';
    }

    public function description(): string
    {
        return 'Returns the work details of the employee who is asking: name, department, position, employment type, hire date, years of service and who they report to (their supervisor). Use it for questions such as "who is my supervisor?" or "when did I join?". It cannot look up anyone else.';
    }

    public function dataSent(): string
    {
        return "The employee's name, department, position, employment type, hire date and supervisor (name only). Nothing else of the employee record.";
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function run(Employee $employee, array $input): array
    {
        $supervisor = $employee->effectiveSupervisor();

        return Arr::whereNotNull([
            'name' => $employee->name,
            'department' => $employee->department?->name,
            // The title only, read without the model: a position also holds the salary
            'position' => $employee->position_id !== null ? DB::table('positions')->where('id', $employee->position_id)->value('title') : null,
            'employment_type' => $employee->employment_type,
            'hire_date' => $employee->hire_date?->toDateString(),
            'years_of_service' => $employee->hire_date !== null ? round($employee->hire_date->diffInYears(now()), 1) : null,
            'supervisor' => $supervisor?->name,
        ]);
    }
}
