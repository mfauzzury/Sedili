<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        @if (\Filament\Facades\Filament::getCurrentPanel()?->getId() === 'admin')
            Approved leave in colour, pending leave in light yellow, public holidays in grey.
        @else
            Your colleagues' approved leave (without the leave type), your own leave in blue, and the public holidays in grey.
        @endif
    </p>
</x-filament-panels::page>
