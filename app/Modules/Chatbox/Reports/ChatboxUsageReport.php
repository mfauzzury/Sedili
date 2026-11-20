<?php

namespace App\Modules\Chatbox\Reports;

use App\Modules\Chatbox\Models\ChatMessage;
use App\Modules\Report;
use App\Modules\ReportChart;
use App\Modules\ReportColumn;
use App\Modules\ReportFilters;
use App\Modules\ReportResult;
use App\Modules\ReportStat;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * How much the chat assistant is used, day by day. Counts only: the conversations are private to
 * the employees and are never shown here.
 */
class ChatboxUsageReport extends Report
{
    public function key(): string
    {
        return 'usage';
    }

    public function title(): string
    {
        return 'Chatbox usage';
    }

    public function description(): string
    {
        return 'Questions asked to the assistant and the tokens used, by day. Never shows what was asked.';
    }

    public function icon(): string
    {
        return 'heroicon-o-chat-bubble-left-right';
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $rows = $this->base($filters)
            ->selectRaw('DATE(chatbox_messages.created_at) as day')
            ->selectRaw("SUM(CASE WHEN chatbox_messages.role = 'user' THEN 1 ELSE 0 END) as questions")
            ->selectRaw("COUNT(DISTINCT CASE WHEN chatbox_messages.role = 'user' THEN c.employee_id END) as employees")
            ->selectRaw('COALESCE(SUM(chatbox_messages.input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(chatbox_messages.output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(chatbox_messages.cache_read_tokens), 0) as cache_read_tokens')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row): array => [
                'day' => (string) $row->day,
                'questions' => (int) $row->questions,
                'employees' => (int) $row->employees,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'cache_read_tokens' => (int) $row->cache_read_tokens,
            ]);

        $activeEmployees = (int) $this->base($filters)->where('chatbox_messages.role', ChatMessage::USER)->distinct()->count('c.employee_id');
        $input = $rows->sum('input_tokens');
        $cached = $rows->sum('cache_read_tokens');
        $allInput = $input + $cached;

        return new ReportResult(
            columns: [
                ReportColumn::date('day', 'Day'),
                ReportColumn::number('questions', 'Questions'),
                ReportColumn::number('employees', 'Employees'),
                ReportColumn::number('input_tokens', 'Input tokens'),
                ReportColumn::number('cache_read_tokens', 'Read from cache'),
                ReportColumn::number('output_tokens', 'Output tokens'),
            ],
            rows: $rows->all(),
            stats: [
                new ReportStat('Questions', Number::format($rows->sum('questions'))),
                new ReportStat('Employees who asked', Number::format($activeEmployees)),
                new ReportStat('Tokens used', Number::format($input + $rows->sum('output_tokens')), 'Input and output, without the cache'),
                new ReportStat('Read from cache', $allInput > 0 ? round($cached / $allInput * 100).'%' : '-', 'Share of the input (the handbook is cached)'),
            ],
            chart: ReportChart::line('Questions per day', $rows->pluck('questions', 'day')->all()),
            note: 'The conversations are private to the employees: this report shows counts only.',
        );
    }

    /**
     * The messages of the period (and department), joined to their chat for the employee.
     */
    private function base(ReportFilters $filters): Builder
    {
        $query = DB::table('chatbox_messages')->join('chatbox_conversations as c', 'c.id', '=', 'chatbox_messages.conversation_id');
        $filters->whereInPeriod($query, 'chatbox_messages.created_at');
        $filters->whereEmployee($query, 'c.employee_id');

        return $query;
    }
}
