<x-filament-panels::page>
    {{-- The page body comes before the footer widget (the calendar); .leave-legend in the theme moves it after the calendar --}}
    <div class="leave-legend space-y-3">
    <div class="text-sm text-gray-600 dark:text-gray-300" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 24px;">
        @foreach ($this->getLegend() as $item)
            <span style="display:inline-flex;align-items:center;gap:8px;">
                <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:{{ $item['color'] }};"></span>{{ $item['label'] }}
            </span>
        @endforeach
    </div>
    <p class="text-xs text-gray-400">
        @if ($this->isAdminPanel())
            Pending requests are marked "(pending)". The colour of a leave type is set in Leave Types. Click "+N more" on a busy day to see everyone.
        @else
            The leave type and the reason of your colleagues stay private. Click "+N more" on a busy day to see everyone.
        @endif
    </p>
    </div>
</x-filament-panels::page>
