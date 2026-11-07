@php($isAdmin = \Filament\Facades\Filament::getCurrentPanel()?->getId() === 'admin')
<x-filament-panels::page>
    <div class="text-sm text-gray-600 dark:text-gray-300" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 28px;">
        <span style="display:inline-flex;align-items:center;gap:8px;"><span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:#16a34a"></span>{{ $isAdmin ? 'Approved leave' : 'Colleague on leave' }}</span>
        @unless ($isAdmin)
            <span style="display:inline-flex;align-items:center;gap:8px;"><span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:#0891b2"></span>My approved leave</span>
        @endunless
        <span style="display:inline-flex;align-items:center;gap:8px;"><span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:#fde68a"></span>{{ $isAdmin ? 'Pending approval' : 'My pending request' }}</span>
        <span style="display:inline-flex;align-items:center;gap:8px;"><span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:#e5e7eb"></span>Public holiday</span>
    </div>
    <p class="text-xs text-gray-400">
        @if ($isAdmin)
            When many people are away on the same day, click "+N more" to see everyone.
        @else
            The leave type and the reason of your colleagues stay private. Click "+N more" on a busy day to see everyone.
        @endif
    </p>
</x-filament-panels::page>
