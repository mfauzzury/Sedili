<x-filament-widgets::widget>
    @php($balances = $this->getBalances())
    @if ($balances)
        <div class="lb-grid">
            @foreach ($balances as $balance)
                <div class="lb-tile" title="{{ $balance['name'] }}: {{ $balance['left'] }} of {{ $balance['total'] }} days left">
                    {{-- A ring of 100 units: the days left from the top (colour of the leave type), then the pending days (amber), the rest is grey (taken) --}}
                    <svg class="lb-ring" viewBox="0 0 36 36" role="img" aria-label="{{ $balance['name'] }}: {{ $balance['left'] }} of {{ $balance['total'] }} days left">
                        <circle class="lb-track" cx="18" cy="18" r="15.9155" fill="none" stroke-width="4" />
                        @if ($balance['pendingShare'] > 0)
                            <circle class="lb-pending" cx="18" cy="18" r="15.9155" fill="none" stroke-width="4"
                                    stroke-dasharray="{{ round($balance['pendingShare'], 2) }} {{ round(100 - $balance['pendingShare'], 2) }}"
                                    stroke-dashoffset="{{ -round($balance['remaining'], 2) }}" transform="rotate(-90 18 18)" />
                        @endif
                        @if ($balance['remaining'] > 0)
                            <circle cx="18" cy="18" r="15.9155" fill="none" stroke-width="4" stroke-linecap="butt"
                                    stroke="{{ $balance['color'] }}"
                                    stroke-dasharray="{{ round($balance['remaining'], 2) }} {{ round(100 - $balance['remaining'], 2) }}"
                                    transform="rotate(-90 18 18)" />
                        @endif
                        <text x="18" y="18" class="lb-center {{ $balance['over'] ? 'lb-over' : '' }}" text-anchor="middle" dominant-baseline="central">{{ $balance['left'] }}</text>
                    </svg>
                    <div class="lb-text">
                        <div class="lb-name">
                            <span class="lb-dot" style="background: {{ $balance['color'] }}"></span>{{ $balance['name'] }}
                        </div>
                        <div class="lb-meta">{{ $balance['left'] }} of {{ $balance['total'] }} days left</div>
                        <div class="lb-meta">
                            {{ $balance['taken'] }} taken
                            @if ((float) $balance['pending'] > 0)
                                · <span class="lb-pending-text">{{ $balance['pending'] }} pending</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-filament-widgets::widget>
