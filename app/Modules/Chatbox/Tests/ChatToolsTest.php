<?php

namespace App\Modules\Chatbox\Tests;

use App\Models\Employee;
use App\Modules\Chatbox\Filament\Pages\ChatboxSettingsPage;
use App\Modules\Chatbox\Models\ChatMessage;
use App\Modules\Chatbox\Services\ChatboxAssistant;
use App\Modules\Chatbox\Services\ChatboxSettings;
use App\Modules\Chatbox\Services\ChatToolbox;
use App\Modules\Chatbox\Services\ChatTurn;
use App\Modules\Leave\Database\Seeders\LeaveTypeSeeder;
use App\Modules\Leave\Models\Leave;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesEmployees;
use Tests\TestCase;

/**
 * The data the assistant may look up for an employee (their leave balance...): which tools are offered,
 * how a lookup is run and given back to Claude, and that it can never reach anyone else's data.
 */
class ChatToolsTest extends TestCase
{
    use CreatesEmployees;
    use MakesChatbox;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdminPanel();
        $this->seed(LeaveTypeSeeder::class);
        $this->fakeClaude();
        $this->setUpAssistant();
    }

    private function employeeWithLeave(string $name, int $approvedDays): Employee
    {
        $employee = $this->makeEmployee($name, attributes: ['gender' => 'Female', 'hire_date' => '2020-01-01']);
        if ($approvedDays > 0) {
            $monday = CarbonImmutable::create((int) now()->year, 3, 1)->next(CarbonImmutable::MONDAY);
            Leave::create([
                'employee_id' => $employee->id,
                'leave_type' => 'Annual Leave',
                'start_date' => $monday->toDateString(),
                'end_date' => $monday->addDays($approvedDays - 1)->toDateString(),
                'status' => 'Approved',
            ]);
        }

        return $employee;
    }

    private function lookup(string $name = 'leave_balance', array $input = [], string $id = 'toolu_1'): ChatTurn
    {
        return new ChatTurn(
            'Let me check.',
            [['type' => 'text', 'text' => 'Let me check.'], ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]],
            [['id' => $id, 'name' => $name, 'input' => $input]],
            inputTokens: 30,
            outputTokens: 5,
        );
    }

    private function ask(Employee $employee, string $question = 'How many leave days do I have left?', ?callable $onDelta = null): ChatMessage
    {
        return app(ChatboxAssistant::class)->ask($employee, null, $question, $onDelta ?? fn () => null);
    }

    // --- Which tools are offered

    public function test_no_tool_is_offered_until_the_admin_switches_it_on(): void
    {
        $this->ask($this->makeEmployee('Alice'));

        $this->assertSame([], $this->claude->calls[0]['tools']);
        $this->assertSame([], app(ChatToolbox::class)->enabled());
        $this->assertContains('leave_balance', array_keys(app(ChatToolbox::class)->available()), 'the Leave module offers it');
    }

    public function test_a_tool_that_is_switched_on_is_offered_to_claude_with_its_description_and_schema(): void
    {
        $this->enableTools(['leave_balance']);

        $this->ask($this->makeEmployee('Alice'));

        $tools = $this->claude->calls[0]['tools'];
        $this->assertCount(1, $tools);
        $this->assertSame('leave_balance', $tools[0]['name']);
        $this->assertStringContainsString('leave balance', $tools[0]['description']);
        $this->assertSame('integer', $tools[0]['input_schema']['properties']['year']['type']);
        $this->assertStringContainsString('look up data', $this->claude->calls[0]['system'][0]['text']);
    }

    public function test_without_tools_the_prompt_says_personal_data_is_out_of_reach(): void
    {
        $this->ask($this->makeEmployee('Alice'));

        $this->assertStringContainsString('cannot look at the personal data', $this->claude->calls[0]['system'][0]['text']);
    }

    public function test_the_tools_of_a_module_that_is_switched_off_are_gone(): void
    {
        $this->enableTools(['leave_balance']);
        $this->assertCount(1, app(ChatToolbox::class)->definitions());

        app(ModuleManager::class)->setEnabled('leave', false);

        $this->assertArrayNotHasKey('leave_balance', app(ChatToolbox::class)->available());
        $this->assertSame([], app(ChatToolbox::class)->definitions());
    }

    public function test_the_assistant_is_not_ready_when_the_only_data_it_could_look_up_is_gone_with_its_module(): void
    {
        \App\Modules\Chatbox\Models\ChatDocument::query()->delete();
        $this->enableTools(['leave_balance']);
        $settings = app(ChatboxSettings::class);
        $this->assertTrue($settings->isReady(), 'a tool is enough to answer from');
        $this->assertSame(['leave_balance'], $settings->usableTools());

        app(ModuleManager::class)->setEnabled('leave', false);

        $this->assertSame(['leave_balance'], $settings->enabledTools(), 'the choice is kept');
        $this->assertSame([], $settings->usableTools());
        $this->assertFalse($settings->isReady(), 'but there is nothing left to answer from');
    }

    // --- Looking things up

    public function test_claude_looks_up_the_balance_of_the_employee_and_continues_with_it(): void
    {
        $this->enableTools(['leave_balance']);
        $alice = $this->employeeWithLeave('Alice', approvedDays: 2);
        $this->claude->script = [
            $this->lookup(),
            new ChatTurn('You have 6 days of annual leave left.', inputTokens: 50, outputTokens: 9, cacheReadTokens: 100),
        ];
        $streamed = [];

        $answer = $this->ask($alice, onDelta: function (string $delta) use (&$streamed): void {
            $streamed[] = $delta;
        });

        $this->assertCount(2, $this->claude->calls);
        $messages = $this->claude->calls[1]['messages'];
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('tool_use', $messages[1]['content'][1]['type'], 'what Claude wrote is given back as it was');
        $this->assertSame('user', $messages[2]['role']);
        $result = $messages[2]['content'][0];
        $this->assertSame(['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'is_error' => false], array_diff_key($result, ['content' => 1]));

        $data = json_decode($result['content'], true);
        $annual = collect($data['balances'])->firstWhere('leave_type', 'Annual Leave');
        $this->assertSame(2.0, (float) $annual['taken_days']);
        $this->assertSame((int) now()->year, $data['year']);
        $this->assertSame(now()->toDateString(), $data['as_of'], 'the date of the lookup is added by the chat, not by the tool');

        $this->assertSame("Let me check.\n\nYou have 6 days of annual leave left.", $answer->content);
        $this->assertSame(['Let me check.', "\n\nYou have 6 days of annual leave left."], $streamed);
        $this->assertSame([80, 14, 100], [$answer->input_tokens, $answer->output_tokens, $answer->cache_read_tokens]);
    }

    public function test_a_lookup_only_ever_sees_the_employee_who_is_chatting(): void
    {
        $this->enableTools(['leave_balance']);
        $alice = $this->employeeWithLeave('Alice', approvedDays: 2);
        $bob = $this->employeeWithLeave('Bob', approvedDays: 5);
        // A prompt injection: the model is told to ask for somebody else
        $this->claude->script = [
            $this->lookup(input: ['employee_id' => $bob->id, 'email' => $bob->email, 'user' => 'Bob Test']),
            new ChatTurn('Done.'),
        ];

        $this->ask($alice);

        $data = json_decode($this->claude->calls[1]['messages'][2]['content'][0]['content'], true);
        $annual = collect($data['balances'])->firstWhere('leave_type', 'Annual Leave');
        $this->assertSame(2.0, (float) $annual['taken_days'], "Alice's days, not Bob's 5");
        $this->assertStringNotContainsString('Bob', json_encode($data));
    }

    public function test_a_tool_that_is_not_switched_on_gives_an_error_and_no_data(): void
    {
        $alice = $this->employeeWithLeave('Alice', approvedDays: 2);
        $this->claude->script = [$this->lookup(), new ChatTurn('Sorry, I cannot see that.')];

        $this->ask($alice);

        $result = $this->claude->calls[1]['messages'][2]['content'][0];
        $this->assertTrue($result['is_error']);
        $this->assertStringNotContainsString('Annual Leave', $result['content']);
    }

    public function test_an_unknown_tool_gives_an_error(): void
    {
        $this->enableTools(['leave_balance']);
        $this->claude->script = [$this->lookup('payroll_everyones_salary'), new ChatTurn('Sorry.')];

        $this->ask($this->makeEmployee('Alice'));

        $this->assertTrue($this->claude->calls[1]['messages'][2]['content'][0]['is_error']);
    }

    public function test_a_failing_tool_does_not_leak_its_details_and_does_not_break_the_chat(): void
    {
        $modules = $this->modulesOffering(['x_boom' => new FakeChatTool('boom', fn () => throw new RuntimeException('SQLSTATE secret detail'))]);
        $this->enableTools(['x_boom']);
        $toolbox = new ChatToolbox($modules, app(ChatboxSettings::class));

        $result = $toolbox->run('x_boom', [], $this->makeEmployee('Alice'));

        $this->assertTrue($result['isError']);
        $this->assertStringNotContainsString('secret detail', $result['content']);
    }

    public function test_a_result_that_is_too_long_is_refused_instead_of_cut_into_broken_json(): void
    {
        $modules = $this->modulesOffering(['x_big' => new FakeChatTool('big', fn () => ['text' => str_repeat('a', 100_000)])]);
        $this->enableTools(['x_big']);

        $result = (new ChatToolbox($modules, app(ChatboxSettings::class)))->run('x_big', [], $this->makeEmployee('Alice'));

        $this->assertTrue($result['isError']);
        $this->assertLessThan(200, mb_strlen($result['content']));
        $this->assertStringNotContainsString('aaaa', $result['content']);
    }

    public function test_claude_cannot_look_things_up_forever(): void
    {
        $this->enableTools(['leave_balance']);
        $this->claude->script = array_map(fn (int $i): ChatTurn => $this->lookup(id: "toolu_$i"), range(1, 10));

        $answer = $this->ask($this->makeEmployee('Alice'));

        $this->assertCount(ChatboxAssistant::MAX_TOOL_ROUNDS + 1, $this->claude->calls);
        $this->assertSame('Let me check.'.str_repeat("\n\nLet me check.", ChatboxAssistant::MAX_TOOL_ROUNDS), $answer->content);
    }

    public function test_a_refusal_during_a_lookup_ends_the_question_politely(): void
    {
        $this->enableTools(['leave_balance']);
        $this->claude->script = [new ChatTurn('', refused: true)];

        $this->assertSame(ChatboxAssistant::REFUSED, $this->ask($this->makeEmployee('Alice'))->content);
    }

    public function test_the_hourly_limit_counts_a_question_once_however_many_lookups_it_needs(): void
    {
        $this->enableTools(['leave_balance']);
        app(ChatboxSettings::class)->update(['hourly_limit' => 2]);
        $alice = $this->makeEmployee('Alice');
        \Illuminate\Support\Facades\RateLimiter::clear('chatbox:'.$alice->id);
        $this->claude->script = [$this->lookup(), $this->lookup(id: 'toolu_2'), new ChatTurn('Done.')];

        $this->ask($alice);
        $this->ask($alice, 'Second?');

        $this->assertCount(4, $this->claude->calls);
    }

    // --- The Data access tab

    public function test_the_tab_lists_the_tools_with_what_they_send_and_saves_the_choice(): void
    {
        $this->loginAsAdmin();

        $page = Livewire::test(ChatboxSettingsPage::class)
            ->assertSee('Leave: Leave balance')
            ->assertSee('Leave: My leave requests')
            ->assertSee('Sends to Claude')
            ->assertSet('data.tools', []);

        $page->set('data.tools', ['leave_balance'])->call('save')->assertHasNoFormErrors();

        $this->assertSame(['leave_balance'], app(ChatboxSettings::class)->enabledTools());
    }

    public function test_every_module_that_offers_lookups_is_listed_in_the_tab(): void
    {
        $this->loginAsAdmin();

        $this->assertEqualsCanonicalizing(
            ['chatbox_profile', 'claims_status', 'leave_balance', 'leave_requests', 'tasks_open'],
            array_keys(app(ChatToolbox::class)->available()),
        );

        Livewire::test(ChatboxSettingsPage::class)
            ->assertSee('Claims: My claims')
            ->assertSee('Task Board: My tasks')
            ->assertSee('Chatbox: My work profile')
            ->assertSee('Nothing else of the employee record');
    }

    public function test_the_choice_survives_a_module_being_switched_off_and_on(): void
    {
        $this->loginAsAdmin();
        $this->enableTools(['leave_balance']);

        app(ModuleManager::class)->setEnabled('leave', false);
        Livewire::test(ChatboxSettingsPage::class)->call('save');

        $this->assertSame(['leave_balance'], app(ChatboxSettings::class)->enabledTools(), 'saving while the module is off does not forget the choice');
    }
}
