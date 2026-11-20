<?php

namespace App\Modules\Reports\Filament\Pages;

use App\Models\Employee;
use App\Modules\Chatbox\Exceptions\ChatboxException;
use App\Modules\Chatbox\Models\ChatConversation;
use App\Modules\Chatbox\Models\ChatMessage;
use App\Modules\Chatbox\Services\ChatboxSettings;
use App\Modules\ModuleManager;
use App\Modules\Reports\Insight\InsightAssistant;
use App\Modules\Reports\Insight\ReportTool;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Insight: a chat screen where an admin asks about the figures of the company in plain words. Claude
 * runs the reports it needs; the answer comes with the chart and the table of each of them, drawn by
 * the server. Set up in System > Chatbox > Insight, with the same Claude key as the chat of the portal.
 * An admin only sees their own chats.
 */
class Insight extends Page
{
    private const SESSION_KEY = 'insight.conversation';

    private const HISTORY_SIZE = 30;

    /** Rows of a table shown under an answer: the full report is one click away */
    public const TABLE_ROWS = 10;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-sparkles';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'insight';

    protected static ?string $title = 'Insight';

    protected string $view = 'reports::insight';

    #[Locked]
    public ?int $conversationId = null;

    public ?string $error = null;

    /** @var array<int, list<array<string, mixed>>> visuals of the messages, drawn once per request */
    private array $drawn = [];

    /**
     * Only once the Chatbox module is on and Insight is set up: without the Claude key the screen has
     * nothing to answer with. (The Chatbox is not a requirement of Reports: Reports works without it.)
     */
    public static function canAccess(): bool
    {
        return app(ModuleManager::class)->isEnabled('chatbox')
            && app(ChatboxSettings::class)->isInsightReady();
    }

    public function mount(): void
    {
        $id = session(self::SESSION_KEY);
        if ($id !== null && $this->ownConversations()->whereKey($id)->exists()) {
            $this->conversationId = (int) $id;
        }
    }

    public function getBreadcrumbs(): array
    {
        return [Reports::getUrl() => 'Reports', 'Insight'];
    }

    public function ask(string $question): void
    {
        $this->error = null;

        /** @var Employee $admin */
        $admin = Auth::user();
        $conversation = $this->conversationId !== null
            ? $this->ownConversations()->find($this->conversationId)
            : null;

        try {
            $message = app(InsightAssistant::class)->ask(
                $admin,
                $conversation,
                $question,
                // wire:stream writes the content as HTML: escape it, the text of the model is not trusted
                fn (string $delta) => $this->stream(to: 'answer', content: e($delta)),
            );
            $this->remember($message->conversation_id);
        } catch (ChatboxException $e) {
            $this->error = $e->getMessage();
            if ($e->conversationId !== null) {
                $this->remember($e->conversationId);
            }
        }

        unset($this->messages, $this->conversations);
    }

    public function newChat(): void
    {
        $this->error = null;
        $this->conversationId = null;
        session()->forget(self::SESSION_KEY);
    }

    public function openChat(int $id): void
    {
        $this->error = null;
        if ($this->ownConversations()->whereKey($id)->exists()) {
            $this->remember($id);
        }
    }

    public function deleteChat(int $id): void
    {
        $this->ownConversations()->whereKey($id)->delete();
        if ($this->conversationId === $id) {
            $this->newChat();
        }
        unset($this->conversations);
    }

    /** @return Collection<int, ChatMessage> */
    #[Computed]
    public function messages(): Collection
    {
        if ($this->conversationId === null) {
            return new Collection;
        }

        $conversation = $this->ownConversations()->find($this->conversationId);

        return $conversation === null ? new Collection : $conversation->messages;
    }

    /** @return Collection<int, ChatConversation> */
    #[Computed]
    public function conversations(): Collection
    {
        return $this->ownConversations()->latest('updated_at')->latest('id')->limit(self::HISTORY_SIZE)->get();
    }

    /** What is shown under an answer: "full" (charts and tables), "tables" or "text" */
    public function visualsMode(): string
    {
        return app(ChatboxSettings::class)->insightVisuals();
    }

    /**
     * The reports run for an answer, drawn again with today's data and the same filters. A report whose
     * module was switched off since is left out.
     *
     * @return list<array<string, mixed>>
     */
    public function visualsOf(ChatMessage $message): array
    {
        if ($this->visualsMode() === 'text' || empty($message->visuals)) {
            return [];
        }

        return $this->drawn[$message->id] ??= array_values(array_filter(array_map(
            fn (array $visual): ?array => app(ReportTool::class)->resolve($visual),
            $message->visuals,
        )));
    }

    /** @return list<string> */
    public function suggestions(): array
    {
        return [
            'Headcount by department',
            'Who joined and who left this year?',
            'Claims by type this quarter',
            'Which employees have the most unused annual leave?',
        ];
    }

    private function remember(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        session()->put(self::SESSION_KEY, $conversationId);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<ChatConversation> */
    private function ownConversations()
    {
        return ChatConversation::query()->where('employee_id', Auth::id())->where('kind', ChatConversation::INSIGHT);
    }
}
