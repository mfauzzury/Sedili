<?php

namespace App\Modules\Chatbox\Livewire;

use App\Models\Employee;
use App\Modules\Chatbox\Exceptions\ChatboxException;
use App\Modules\Chatbox\Models\ChatConversation;
use App\Modules\Chatbox\Models\ChatMessage;
use App\Modules\Chatbox\Services\ChatboxAssistant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The chat bubble of the employee portal: a button at the bottom of every page that opens a small
 * chat with the assistant. The answer is streamed while Claude writes it. An employee only ever
 * sees their own chats.
 */
class ChatWidget extends Component
{
    private const SESSION_KEY = 'chatbox.conversation';

    private const HISTORY_SIZE = 30;

    #[Locked]
    public ?int $conversationId = null;

    public ?string $error = null;

    public function mount(): void
    {
        // The portal swaps pages without a reload: go on with the chat the employee was in
        $id = session(self::SESSION_KEY);
        if ($id !== null && $this->ownConversations()->whereKey($id)->exists()) {
            $this->conversationId = (int) $id;
        }
    }

    public function ask(string $question): void
    {
        $this->error = null;

        /** @var Employee $employee */
        $employee = Auth::user();
        $conversation = $this->conversationId !== null
            ? $this->ownConversations()->find($this->conversationId)
            : null;

        try {
            $message = app(ChatboxAssistant::class)->ask(
                $employee,
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

    public function render()
    {
        return view('chatbox::chat-widget', [
            'firstName' => Auth::user()?->first_name,
            'suggestions' => [
                'What are the working hours?',
                'How does leave approval work?',
                'What is the dress code?',
            ],
        ]);
    }

    private function remember(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        session()->put(self::SESSION_KEY, $conversationId);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<ChatConversation> */
    private function ownConversations()
    {
        return ChatConversation::query()->where('employee_id', Auth::id());
    }
}
