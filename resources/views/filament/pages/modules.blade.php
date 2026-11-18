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
                        {{-- Only while enabled: the pages of a disabled module are not registered --}}
                        @if ($enabled && ($settingsUrl = $module->settingsUrl()))
                            <x-filament::link :href="$settingsUrl" icon="heroicon-m-cog-6-tooth" size="sm" class="mt-1">
                                Settings
                            </x-filament::link>
                        @endif
                    </div>
                    {{-- Filament's own toggle classes, driven by the saved state rather than browser state, so a cancelled confirmation leaves the switch where it was --}}
                    <button
                        type="button"
                        role="switch"
                        aria-checked="{{ $enabled ? 'true' : 'false' }}"
                        aria-label="{{ $module->label() }}"
                        wire:click="toggle('{{ $module->key() }}')"
                        wire:loading.attr="disabled"
                        wire:target="toggle"
                        @if ($enabled)
                            wire:confirm="Disable {{ $module->label() }}? Its menu and screens are hidden, its data is kept."
                        @endif
                        @class([
                            'fi-toggle',
                            ...($enabled
                                ? ['fi-toggle-on', ...\Filament\Support\get_component_color_classes(\Filament\Support\View\Components\ToggleComponent::class, 'primary')]
                                : ['fi-toggle-off', ...\Filament\Support\get_component_color_classes(\Filament\Support\View\Components\ToggleComponent::class, 'gray')]),
                        ])
                    >
                        <div>
                            <div aria-hidden="true"></div>
                            <div aria-hidden="true"></div>
                        </div>
                    </button>
                </div>
            </x-filament::section>
        @endforeach
    </div>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Always on: Employees, Departments, Admins and the dashboard.
    </p>
</x-filament-panels::page>
