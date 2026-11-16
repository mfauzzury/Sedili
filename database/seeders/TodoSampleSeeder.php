<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Modules\Inbox\Models\Message;
use App\Modules\Inbox\Models\Topic;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Seeder;

/**
 * Sample tasks and unread messages, to see the "To do" list of the portal dashboard. Not part of
 * DatabaseSeeder; run it on demand (after OrgChartSampleSeeder, whose employees it uses):
 *
 *   php artisan db:seed --class=TodoSampleSeeder
 *
 * It can be run again (tasks and topics are matched on their title and subject). The due dates are
 * relative to today, so the list always shows a late, a due-today and an upcoming task.
 */
class TodoSampleSeeder extends Seeder
{
    /** [assignee email, title, status, days from today (null: no due date)] */
    private const TASKS = [
        ['ahmad.faizal@example.com', 'Send the payroll figures to Finance', 'in_progress', -3],
        ['ahmad.faizal@example.com', 'Review the new employee contracts', 'todo', 0],
        ['ahmad.faizal@example.com', 'Book the venue for the year-end dinner', 'todo', 1],
        ['ahmad.faizal@example.com', 'Update the leave policy document', 'todo', 9],
        ['ahmad.faizal@example.com', 'Tidy up the shared HR folder', 'todo', null],
        ['ahmad.faizal@example.com', 'Collect the signed offer letters', 'completed', -10],
        ['farid.hakim@example.com', 'Plan the next sprint', 'todo', 2],
        ['farid.hakim@example.com', 'Review the architecture proposal', 'in_progress', -1],
        ['elaine.goh@example.com', 'Prepare the product roadmap for Q1', 'todo', 5],
        ['syafiqah.zulkifli@example.com', 'Reply to the open support tickets', 'in_progress', 0],
    ];

    public function run(): void
    {
        $position = [];
        Task::withoutEvents(function () use (&$position) {
            foreach (self::TASKS as [$email, $title, $status, $days]) {
                $assignee = Employee::where('email', $email)->first();
                if ($assignee === null) {
                    continue;
                }
                $position[$status] = ($position[$status] ?? (int) Task::where('status', $status)->max('position')) + 1;
                Task::firstOrCreate(
                    ['title' => $title, 'assignee_id' => $assignee->id],
                    [
                        'status' => $status,
                        'due_date' => $days === null ? null : now()->addDays($days)->toDateString(),
                        'position' => $position[$status],
                    ],
                );
            }
        });

        // Two unread messages from the admin to Ahmad
        $admin = Employee::role('admin')->first();
        $ahmad = Employee::where('email', 'ahmad.faizal@example.com')->first();
        if ($admin !== null && $ahmad !== null) {
            Message::withoutEvents(function () use ($admin, $ahmad) {
                $topic = Topic::firstOrCreate(
                    ['subject' => 'Year-end leave planning', 'creator_id' => $admin->id, 'receiver_id' => $ahmad->id],
                );
                if (!$topic->message()->exists()) {
                    foreach (['Please confirm the year-end leave dates for your team.', 'We need them before the 30th.'] as $content) {
                        Message::create([
                            'topic_id' => $topic->id,
                            'sender_id' => $admin->id,
                            'receiver_id' => $ahmad->id,
                            'content' => $content,
                        ]);
                    }
                }
            });
        }
    }
}
