<?php

namespace App\Modules\Reports\Tests;

use App\Models\Department;
use App\Models\Employee;
use App\Modules\ReportFilters;
use Carbon\CarbonImmutable;

/**
 * People and filters for the tests of the reports.
 */
trait MakesReportData
{
    private function employee(string $email, array $attributes = [], string $role = 'employee'): Employee
    {
        $employee = new Employee;
        $employee->forceFill([
            'first_name' => ucfirst(strtok($email, '@')),
            'last_name' => 'Test',
            'email' => $email,
            'password' => 'password',
            'employment_type' => 'Permanent',
            'gender' => 'Female',
            'hire_date' => '2018-01-01',
            ...$attributes,
        ])->save();
        $employee->assignRole($role);

        return $employee;
    }

    private function department(string $name): Department
    {
        return Department::create(['name' => $name]);
    }

    private function filters(string $from, string $to, ?Department $department = null): ReportFilters
    {
        return new ReportFilters(CarbonImmutable::parse($from), CarbonImmutable::parse($to), $department?->id);
    }

    /**
     * The value of a headline figure, by label.
     *
     * @param  list<\App\Modules\ReportStat>  $stats
     */
    private function stat(array $stats, string $label): string
    {
        foreach ($stats as $stat) {
            if ($stat->label === $label) {
                return $stat->value;
            }
        }
        $this->fail("No figure [$label].");
    }
}
