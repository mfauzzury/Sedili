<?php

namespace App\Modules;

/**
 * One thing an employee has to do, shown on the dashboard of the portal (see Module::todos()).
 */
final class Todo
{
    /**
     * @param string $title what to do ("Prepare the budget", "3 unread messages")
     * @param string $url where to do it
     * @param string|null $detail a line under the title (status, who asked...)
     * @param string|null $badge short text on the right (a due date...)
     * @param 'danger'|'warning'|'gray' $color urgency: danger is late, warning is for soon
     * @param string $icon heroicon name
     */
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $detail = null,
        public readonly ?string $badge = null,
        public readonly string $color = 'gray',
        public readonly string $icon = 'heroicon-o-check-circle',
    ) {
    }
}
