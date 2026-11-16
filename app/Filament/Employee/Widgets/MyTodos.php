<?php

namespace App\Filament\Employee\Widgets;

use App\Models\Employee;
use App\Modules\ModuleManager;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * What the employee has to do, collected from every enabled module (see Module::todos()):
 * open tasks, unread messages, leave requests to approve...
 */
class MyTodos extends Widget
{
    protected string $view = 'filament.widgets.my-todos';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, array{label: string, todos: list<\App\Modules\Todo>}>
     */
    public function getGroups(): array
    {
        $employee = Auth::user();
        $groups = $employee instanceof Employee ? app(ModuleManager::class)->todos($employee, 'portal') : [];

        // The group with the most urgent thing first (late, then soon, then the rest), then by name
        $urgency = ['danger' => 0, 'warning' => 1, 'gray' => 2];
        $rank = fn(array $group): int => min(array_map(fn($todo) => $urgency[$todo->color] ?? 2, $group['todos']));
        uasort($groups, fn($a, $b) => [$rank($a), $a['label']] <=> [$rank($b), $b['label']]);

        return $groups;
    }
}
