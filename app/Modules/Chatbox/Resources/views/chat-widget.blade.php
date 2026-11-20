<div
    class="chatbox"
    x-data="{
        open: $persist(false).as('chatbox-open'),
        view: 'chat',
        text: '',
        pending: null,
        busy: false,
        scroll() {
            const list = this.$refs.list
            if (list) list.scrollTop = list.scrollHeight
        },
        async send(question = null) {
            const q = (question ?? this.text).trim()
            if (!q || this.busy) return
            this.view = 'chat'
            this.pending = q
            this.text = ''
            this.busy = true
            this.$refs.stream.innerHTML = ''
            this.$nextTick(() => this.scroll())
            try {
                await this.$wire.ask(q)
            } finally {
                this.pending = null
                this.busy = false
                this.$nextTick(() => this.scroll())
            }
        },
    }"
    x-init="$nextTick(() => new MutationObserver(() => scroll()).observe($refs.list, { childList: true, subtree: true, characterData: true }))"
    x-on:keydown.escape.window="open = false"
>
    <section
        class="chatbox-panel"
        x-show="open"
        x-cloak
        x-transition.opacity.duration.150ms
        role="dialog"
        aria-label="Ask HR"
    >
        <header class="chatbox-header">
            <div class="chatbox-title">
                <span class="chatbox-title-main" x-text="view === 'history' ? 'My chats' : 'Ask HR'"></span>
                <span class="chatbox-title-sub" x-show="view === 'chat'">Answers come from the company handbook</span>
            </div>
            <div class="chatbox-header-actions">
                <button type="button" class="chatbox-icon-btn" title="New chat" aria-label="New chat"
                    x-on:click="view = 'chat'; $wire.newChat()" x-bind:disabled="busy">
                    <x-filament::icon icon="heroicon-o-pencil-square" class="chatbox-icon" />
                </button>
                <button type="button" class="chatbox-icon-btn" title="My chats" aria-label="My chats"
                    x-on:click="view = view === 'history' ? 'chat' : 'history'" x-bind:class="{ 'is-active': view === 'history' }">
                    <x-filament::icon icon="heroicon-o-clock" class="chatbox-icon" />
                </button>
                <button type="button" class="chatbox-icon-btn" title="Close" aria-label="Close"
                    x-on:click="open = false">
                    <x-filament::icon icon="heroicon-o-x-mark" class="chatbox-icon" />
                </button>
            </div>
        </header>

        <div class="chatbox-history" x-show="view === 'history'" x-cloak>
            @forelse ($this->conversations as $conversation)
                <div class="chatbox-history-row" wire:key="chat-{{ $conversation->id }}">
                    <button type="button" class="chatbox-history-open"
                        wire:click="openChat({{ $conversation->id }})" x-on:click="view = 'chat'">
                        <span class="chatbox-history-title">{{ $conversation->title }}</span>
                        <span class="chatbox-history-time">{{ $conversation->updated_at?->diffForHumans() }}</span>
                    </button>
                    <button type="button" class="chatbox-icon-btn" title="Delete this chat" aria-label="Delete this chat"
                        wire:click="deleteChat({{ $conversation->id }})"
                        wire:confirm="Delete this chat? It cannot be recovered.">
                        <x-filament::icon icon="heroicon-o-trash" class="chatbox-icon" />
                    </button>
                </div>
            @empty
                <p class="chatbox-empty">No chats yet. Your questions are saved here, and only you can see them.</p>
            @endforelse
        </div>

        <div class="chatbox-list" x-ref="list" x-show="view === 'chat'">
            @if ($this->messages->isEmpty())
                <div class="chatbox-welcome" x-show="!pending">
                    <p class="chatbox-welcome-title">Hello{{ $firstName ? ', '.$firstName : '' }}!</p>
                    <p>Ask me about company policies: leave, claims, working hours, conduct and more. I answer from the company handbook.</p>
                    <div class="chatbox-suggestions">
                        @foreach ($suggestions as $suggestion)
                            <button type="button" class="chatbox-suggestion" x-on:click="send(@js($suggestion))" x-bind:disabled="busy">{{ $suggestion }}</button>
                        @endforeach
                    </div>
                </div>
            @endif

            @foreach ($this->messages as $message)
                <div class="chatbox-msg {{ $message->role === 'user' ? 'chatbox-msg--me' : 'chatbox-msg--bot' }}" wire:key="msg-{{ $message->id }}">
                    @if ($message->role === 'user')
                        <span class="chatbox-text">{{ $message->content }}</span>
                    @else
                        <div class="chatbox-prose">{!! $message->html() !!}</div>
                    @endif
                </div>
            @endforeach

            <div class="chatbox-msg chatbox-msg--me" x-show="pending" x-cloak><span class="chatbox-text" x-text="pending"></span></div>
            <div class="chatbox-msg chatbox-msg--bot chatbox-msg--stream" x-show="busy" x-cloak wire:ignore>
                <span class="chatbox-stream" x-ref="stream" wire:stream="answer"></span>
                <span class="chatbox-typing"><i></i><i></i><i></i></span>
            </div>

            @if ($error)
                <div class="chatbox-error" role="alert">{{ $error }}</div>
            @endif
        </div>

        <form class="chatbox-form" x-show="view === 'chat'" x-on:submit.prevent="send()">
            <textarea
                class="chatbox-input"
                rows="1"
                maxlength="{{ \App\Modules\Chatbox\Services\ChatboxAssistant::MAX_QUESTION_LENGTH }}"
                placeholder="Ask a question..."
                aria-label="Your question"
                x-model="text"
                x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); send() }"
                x-bind:disabled="busy"
            ></textarea>
            <button type="submit" class="chatbox-send" aria-label="Send" x-bind:disabled="busy || !text.trim()">
                <x-filament::icon icon="heroicon-m-paper-airplane" class="chatbox-icon" />
            </button>
        </form>
        <p class="chatbox-foot" x-show="view === 'chat'">AI can make mistakes. For anything important, check with HR.</p>
    </section>

    <button type="button" class="chatbox-fab" x-on:click="open = !open; $nextTick(() => scroll())"
        x-bind:aria-expanded="open" aria-label="Ask HR">
        <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="chatbox-fab-icon" x-show="!open" />
        <x-filament::icon icon="heroicon-o-x-mark" class="chatbox-fab-icon" x-show="open" x-cloak />
    </button>
</div>
