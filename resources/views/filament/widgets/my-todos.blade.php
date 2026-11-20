<x-filament-widgets::widget>
    @php($groups = $this->getGroups())
    @php($total = collect($groups)->sum(fn($group) => count($group['todos'])))

    <div class="todo-head">
        <h2 class="todo-title">To do</h2>
        @if ($total > 0)
            <span class="todo-count">{{ $total }}</span>
        @endif
    </div>

    @if ($total === 0)
        <div class="todo-empty">
            <x-filament::icon icon="heroicon-o-check-circle" class="todo-empty-icon" />
            <div>
                <div class="todo-row-title">You are all caught up</div>
                <div class="todo-row-detail">Nothing is waiting for you right now.</div>
            </div>
        </div>
    @else
        <div class="todo-grid">
            @foreach ($groups as $group)
                <section class="todo-card">
                    <h3 class="todo-group">{{ $group['label'] }}</h3>
                    <ul>
                        @foreach ($group['todos'] as $todo)
                            <li>
                                <a href="{{ $todo->url }}" class="todo-row">
                                    <span class="todo-icon todo-{{ $todo->color }}">
                                        <x-filament::icon :icon="$todo->icon" class="todo-icon-svg" />
                                    </span>
                                    <span class="todo-text">
                                        <span class="todo-row-title">{{ $todo->title }}</span>
                                        @if ($todo->detail)
                                            <span class="todo-row-detail">{{ $todo->detail }}</span>
                                        @endif
                                    </span>
                                    @if ($todo->badge)
                                        <span class="todo-badge todo-{{ $todo->color }}">{{ $todo->badge }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
</x-filament-widgets::widget>
