<?php

namespace App\Modules;

use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * What a report is asked for: a period (both dates included) and optionally one department.
 *
 * The department is the current department of the employee: an employee who moved is reported
 * under their new department, also for the past.
 */
final class ReportFilters
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?int $departmentId = null,
    ) {}

    /**
     * Keep the rows of the chosen department, by the column that holds the employee id.
     *
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function whereEmployee(Builder $query, string $column = 'employee_id'): Builder
    {
        if ($this->departmentId === null) {
            return $query;
        }

        return $query->whereIn(
            $column,
            Employee::withTrashed()->where('department_id', $this->departmentId)->select('id')
        );
    }

    /**
     * Keep the rows of which a date column falls in the period. Written as >= from and < the day
     * after: right whether the column holds "Y-m-d" or "Y-m-d 00:00:00" (SQLite stores the date
     * casts with the time), and an index on the column can still be used.
     *
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function whereInPeriod(Builder $query, string $column): Builder
    {
        return $query
            ->where($column, '>=', $this->from->toDateString())
            ->where($column, '<', $this->to->addDay()->toDateString());
    }

    /**
     * The employees of the chosen department (all of them without one), deleted ones excluded.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Employee>
     */
    public function employees(): \Illuminate\Database\Eloquent\Builder
    {
        return Employee::query()
            ->when($this->departmentId !== null, fn ($query) => $query->where('department_id', $this->departmentId));
    }

    /**
     * Year of a report by year (self::YEAR).
     */
    public function year(): int
    {
        return $this->from->year;
    }

    /**
     * "1 Jan 2026 - 31 Dec 2026", for the export.
     */
    public function periodLabel(): string
    {
        return $this->from->format('j M Y').' - '.$this->to->format('j M Y');
    }
}
