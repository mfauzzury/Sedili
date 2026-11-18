<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;

/**
 * The hierarchy of the company, from the C-level down: each person sits under the one they report to
 * (Employee::effectiveSupervisor(): the supervisor chosen for them or, without one, the manager of
 * their department), and each department hangs under the person its head reports to (the CTO for
 * Software Development, for example). Departments whose head reports to nobody sit at the top.
 * Employees without a department (the C-level, typically) are not boxed in a department: they sit at
 * the top, or under the person they report to. Only active employees are shown; admins are not part
 * of the chart.
 *
 * A node is either a person or a department:
 *  - person:     ['type' => 'person', 'id', 'name', 'title', 'children' => nodes]
 *  - department: ['type' => 'department', 'id', 'name', 'code', 'head', 'count', 'children' => nodes]
 *
 * Inside a department a person reports to someone of the same department; a person who reports to
 * someone from another department, or to nobody, sits right under their own department.
 */
class OrgChart
{
    /**
     * @return array{tree: list<array<string, mixed>>, employees: int, departments: int}
     */
    public function build(): array
    {
        $employees = Employee::query()
            ->role('employee')
            ->withoutRole('admin')
            ->where('is_active', true)
            ->with(['supervisor', 'department.manager'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
        $titles = Position::query()->withoutGlobalScopes()->pluck('title', 'id');

        /** @var array<int, Employee> $byId */
        $byId = $employees->keyBy('id')->all();
        $deptKey = fn(Employee $e): int => $e->department_id ?? 0;

        $departments = Department::query()->with('manager')->orderBy('name')->get()->keyBy('id')->all();
        $keys = array_keys($departments);
        if ($employees->contains(fn(Employee $e) => $e->department_id === null)) {
            $keys[] = 0;
        }

        // Who reports to whom, inside each department
        $parentOf = [];
        foreach ($employees as $employee) {
            $supervisor = $employee->effectiveSupervisor();
            $inSameDepartment = $supervisor !== null
                && isset($byId[$supervisor->id])
                && $deptKey($byId[$supervisor->id]) === $deptKey($employee);
            $parentOf[$employee->id] = $inSameDepartment ? $supervisor->id : null;
        }
        // A loop (A reports to B who reports to A) must not make people disappear: cut it at that person
        foreach ($parentOf as $id => $_) {
            $seen = [$id => true];
            for ($up = $parentOf[$id]; $up !== null; $up = $parentOf[$up]) {
                if (isset($seen[$up])) {
                    $parentOf[$id] = null;
                    break;
                }
                $seen[$up] = true;
            }
        }
        $childrenOf = [];
        $rootsOf = [];
        foreach ($parentOf as $id => $parent) {
            if ($parent === null) {
                $rootsOf[$deptKey($byId[$id])][] = $id;
            } else {
                $childrenOf[$parent][] = $id;
            }
        }

        // The person each department hangs under: whoever its head (or, without a head, its only
        // top person) reports to, when that person belongs to another department
        $attachTo = [];
        foreach ($keys as $key) {
            $attachTo[$key] = null;
            if ($key === 0) {
                continue;
            }
            $manager = ($departments[$key] ?? null)?->manager;
            $head = $manager !== null && isset($byId[$manager->id]) && $deptKey($byId[$manager->id]) === $key
                ? $byId[$manager->id]
                : (count($rootsOf[$key] ?? []) === 1 ? $byId[$rootsOf[$key][0]] : null);
            $above = $head?->effectiveSupervisor();
            if ($above !== null && isset($byId[$above->id]) && $deptKey($byId[$above->id]) !== $key) {
                $attachTo[$key] = $above->id;
            }
        }
        // Two departments hanging under each other would vanish from the chart: cut the loop
        foreach ($keys as $key) {
            $seen = [$key => true];
            for ($up = $attachTo[$key]; $up !== null; $up = $attachTo[$deptKey($byId[$up])]) {
                if (isset($seen[$deptKey($byId[$up])])) {
                    $attachTo[$key] = null;
                    break;
                }
                $seen[$deptKey($byId[$up])] = true;
            }
        }
        $departmentsUnder = [];
        foreach ($attachTo as $key => $person) {
            if ($person !== null) {
                $departmentsUnder[$person][] = $key;
            }
        }

        $person = function (int $id) use (&$person, &$department, $byId, $titles, $childrenOf, $departmentsUnder): array {
            $employee = $byId[$id];
            return [
                'type' => 'person',
                'id' => $employee->id,
                'name' => $employee->name,
                'title' => $employee->position_id ? ($titles[$employee->position_id] ?? null) : null,
                'children' => [
                    ...array_map($person, $childrenOf[$id] ?? []),
                    ...array_map($department, $departmentsUnder[$id] ?? []),
                ],
            ];
        };

        $members = collect($byId)->groupBy($deptKey);
        $department = function (int $key) use (&$person, $departments, $rootsOf, $members, $byId, $deptKey): array {
            $model = $departments[$key] ?? null;
            $manager = $model?->manager;
            return [
                'type' => 'department',
                'id' => $model?->id,
                'name' => $model->name ?? 'No department',
                'code' => $model?->code,
                // Shown on the department when its manager is not one of its people (otherwise they head the people below it)
                'head' => $manager !== null && $manager->is_active && !(isset($byId[$manager->id]) && $deptKey($byId[$manager->id]) === $key) ? $manager->name : null,
                'count' => $members->get($key, collect())->count(),
                'children' => array_map($person, $rootsOf[$key] ?? []),
            ];
        };

        // The people without a department come first, as they are, then the departments nobody hangs under
        $top = [...array_map($person, $rootsOf[0] ?? [])];
        foreach ($keys as $key) {
            if ($key !== 0 && $attachTo[$key] === null) {
                $top[] = $department($key);
            }
        }

        return [
            'tree' => $top,
            'employees' => count($byId),
            'departments' => count(array_diff($keys, [0])),
        ];
    }
}
