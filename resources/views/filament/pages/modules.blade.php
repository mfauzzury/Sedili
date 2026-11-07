<x-filament-panels::page>
    <div class="space-y-4">
        @foreach ($this->getModules() as $module)
            @php($enabled = $this->isEnabled($module->key()))
            <x-filament::section>
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 font-semibold">
                            {{ $module->label() }}
                            <x-filament::badge :color="$enabled ? 'success' : 'gray'">
                                {{ $enabled ? 'Enabled' : 'Disabled' }}
                            </x-filament::badge>
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $module->description() }}</p>
                        @if ($module->requires())
                            <p class="text-xs text-gray-400">Requires: {{ implode(', ', $module->requires()) }}</p>
                        @endif
                    </div>
                    @if ($enabled)
                        <x-filament::button
                            size="sm"
                            outlined
                            color="danger"
                            wire:click="toggle('{{ $module->key() }}')"
                            wire:confirm="Disable {{ $module->label() }}? Its menu and screens are hidden, its data is kept."
                        >
                            Disable
                        </x-filament::button>
                    @else
                        <x-filament::button
                            size="sm"
                            outlined
                            wire:click="toggle('{{ $module->key() }}')"
                        >
                            Enable
                        </x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @endforeach
    </div>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Always on: Employees, Departments, Admins and the dashboard.
    </p>
</x-filament-panels::page>
