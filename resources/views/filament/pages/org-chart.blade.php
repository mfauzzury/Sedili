<x-filament-panels::page>
    @php($chart = $this->getChart())

    @if (count($chart['tree']) === 0)
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">There are no departments yet. Add some under Organization > Departments.</p>
        </x-filament::section>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ $chart['employees'] }} {{ \Illuminate\Support\Str::plural('employee', $chart['employees']) }}
            in {{ $chart['departments'] }} {{ \Illuminate\Support\Str::plural('department', $chart['departments']) }}.
            Each person sits under who they report to: the supervisor set on their profile or, without one, the department manager.
            A department hangs under the person its head reports to. Click a department to expand it, or a person to open their profile.
        </p>

        <x-filament::section>
            <div x-data class="mb-4 flex justify-end gap-2">
                <x-filament::button size="sm" color="gray" outlined x-on:click="$dispatch('org-toggle-all', true)">Expand all</x-filament::button>
                <x-filament::button size="sm" color="gray" outlined x-on:click="$dispatch('org-toggle-all', false)">Collapse all</x-filament::button>
            </div>
            <div class="overflow-x-auto pb-2">
                <div class="org-chart">
                    <ul>
                        <li>
                            <div class="org-node org-company">
                                @if ($chart['logo'])
                                    <img src="{{ $chart['logo'] }}" alt="" class="org-logo">
                                @endif
                                <span class="org-name">{{ $chart['company'] }}</span>
                            </div>
                            <ul>
                                @foreach ($chart['tree'] as $node)
                                    {{-- The departments at the top (the C-level) start open, the others are expanded on click --}}
                                    @include('filament.pages.partials.org-node', ['node' => $node, 'startOpen' => true])
                                @endforeach
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
