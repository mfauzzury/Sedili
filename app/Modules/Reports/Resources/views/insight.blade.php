<x-filament-panels::page>
    <div
        class="relative flex h-[calc(100dvh-13rem)] min-h-[28rem] gap-4"
        x-data="{
            text: '',
            pending: null,
            busy: false,
            chats: false,
            scroll() {
                const list = this.$refs.list
                if (list) list.scrollTop = list.scrollHeight
            },
            async send(question = null) {
                const q = (question ?? this.text).trim()
                if (!q || this.busy) return
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
        x-init="$nextTick(() => { scroll(); new MutationObserver(() => scroll()).observe($refs.list, { childList: true, subtree: true, characterData: true }) })"
    >
        {{-- My chats --}}
        <aside
            class="w-64 shrink-0 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 max-lg:absolute max-lg:inset-y-0 max-lg:left-0 max-lg:z-20 max-lg:shadow-xl lg:flex"
            x-bind:class="chats ? 'max-lg:flex' : 'max-lg:hidden'"
        >
            <div class="p-3">
                <button type="button" wire:click="newChat" x-on:click="chats = false" x-bind:disabled="busy"
                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-500 disabled:opacity-50">
                    <x-filament::icon icon="heroicon-m-plus" class="h-4 w-4" />
                    New chat
                </button>
            </div>
            <div class="min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2 pb-3">
                @forelse ($this->conversations as $conversation)
                    <div @class([
                        'group flex items-center rounded-lg',
                        'bg-gray-100 dark:bg-white/10' => $conversation->id === $conversationId,
                        'hover:bg-gray-50 dark:hover:bg-white/5' => $conversation->id !== $conversationId,
                    ]) wire:key="chat-{{ $conversation->id }}">
                        <button type="button" wire:click="openChat({{ $conversation->id }})" x-on:click="chats = false" x-bind:disabled="busy"
                            class="min-w-0 flex-1 px-3 py-2 text-left">
                            <span class="block truncate text-sm text-gray-950 dark:text-white">{{ $conversation->title }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $conversation->updated_at?->diffForHumans() }}</span>
                        </button>
                        <button type="button" title="Delete this chat" aria-label="Delete this chat"
                            wire:click="deleteChat({{ $conversation->id }})"
                            wire:confirm="Delete this chat? It cannot be recovered."
                            class="mr-1 hidden rounded p-1 text-gray-400 hover:text-danger-600 group-hover:block">
                            <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" />
                        </button>
                    </div>
                @empty
                    <p class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">No chats yet. Your questions are saved here, and only you can see them.</p>
                @endforelse
            </div>
        </aside>

        {{-- The chat --}}
        <section class="flex min-w-0 flex-1 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <header class="flex items-center gap-3 border-b border-gray-200 px-4 py-3 dark:border-white/10">
                <button type="button" class="rounded p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-white/10 lg:hidden" aria-label="My chats" x-on:click="chats = !chats">
                    <x-filament::icon icon="heroicon-o-bars-3" class="h-5 w-5" />
                </button>
                <x-filament::icon icon="heroicon-o-sparkles" class="h-5 w-5 text-primary-600 dark:text-primary-400" />
                <p class="min-w-0 truncate text-sm text-gray-500 dark:text-gray-400">Ask about headcount, leave, claims, attendance and more. Answers come from the reports.</p>
            </header>

            <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4" x-ref="list">
                @if ($this->messages->isEmpty())
                    <div class="mx-auto flex max-w-xl flex-col items-center gap-4 pt-10 text-center" x-show="!pending">
                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                            <x-filament::icon icon="heroicon-o-sparkles" class="h-6 w-6" />
                        </span>
                        <div>
                            <p class="text-lg font-semibold text-gray-950 dark:text-white">What would you like to know?</p>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Ask in your own words. I run the reports and tell you what stands out.</p>
                        </div>
                        <div class="flex flex-wrap justify-center gap-2">
                            @foreach ($this->suggestions() as $suggestion)
                                <button type="button" x-on:click="send(@js($suggestion))" x-bind:disabled="busy"
                                    class="rounded-full border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:border-primary-500 hover:text-primary-600 disabled:opacity-50 dark:border-white/10 dark:text-gray-300 dark:hover:text-primary-400">{{ $suggestion }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif

                @foreach ($this->messages as $message)
                    <div wire:key="msg-{{ $message->id }}" @class(['flex', 'justify-end' => $message->role === 'user'])>
                        @if ($message->role === 'user')
                            <div class="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-md bg-primary-600 px-4 py-2 text-sm text-white">{{ $message->content }}</div>
                        @else
                            <div class="min-w-0 max-w-full flex-1 text-sm text-gray-950 dark:text-white">
                                <div class="chatbox-prose">{!! $message->html() !!}</div>
                                @foreach ($this->visualsOf($message) as $index => $visual)
                                    @include('reports::partials.report-visual', ['visual' => $visual, 'mode' => $this->visualsMode(), 'messageId' => $message->id, 'index' => $index])
                                @endforeach
                                @if (! empty($message->visuals) && $this->visualsMode() !== 'text')
                                    <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">Figures are as of now, not of the day of the question.</p>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach

                <div class="flex justify-end" x-show="pending" x-cloak>
                    <div class="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-md bg-primary-600 px-4 py-2 text-sm text-white" x-text="pending"></div>
                </div>
                <div class="text-sm text-gray-950 dark:text-white" x-show="busy" x-cloak wire:ignore>
                    <div class="chatbox-prose">
                        <span class="chatbox-stream" x-ref="stream" wire:stream="answer"></span>
                        <span class="chatbox-typing"><i></i><i></i><i></i></span>
                    </div>
                </div>

                @if ($error)
                    <div class="rounded-lg bg-danger-50 px-4 py-2 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400" role="alert">{{ $error }}</div>
                @endif
            </div>

            <form class="border-t border-gray-200 p-3 dark:border-white/10" x-on:submit.prevent="send()">
                <div class="flex items-end gap-2">
                    <textarea
                        class="max-h-40 min-h-[2.5rem] flex-1 resize-none rounded-lg border-0 bg-gray-50 px-3 py-2 text-sm text-gray-950 ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10"
                        rows="1"
                        maxlength="{{ \App\Modules\Reports\Insight\InsightAssistant::MAX_QUESTION_LENGTH }}"
                        placeholder="Ask about the figures of the company..."
                        aria-label="Your question"
                        x-model="text"
                        x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); send() }"
                        x-bind:disabled="busy"
                    ></textarea>
                    <button type="submit" aria-label="Send" x-bind:disabled="busy || !text.trim()"
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-600 text-white hover:bg-primary-500 disabled:opacity-40">
                        <x-filament::icon icon="heroicon-m-paper-airplane" class="h-5 w-5" />
                    </button>
                </div>
                <p class="mt-2 text-center text-xs text-gray-400 dark:text-gray-500">AI can make mistakes. Check the figures in the report before you act on them.</p>
            </form>
        </section>
    </div>
</x-filament-panels::page>
