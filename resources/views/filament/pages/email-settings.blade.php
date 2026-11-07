<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit">Save</x-filament::button>
        </div>
    </form>

    @php($options = $this->getPreviewOptions())
    @if ($options)
        <x-filament::section
            heading="Preview"
            description="How the notification emails look, with the logo and the name of the company. The data is a sample."
        >
            <div class="space-y-4">
                <x-filament::input.wrapper class="max-w-xs">
                    <x-filament::input.select wire:model.live="previewEmail">
                        @foreach ($options as $label)
                            <option value="{{ $label }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                {{-- Sandboxed: no script of the email can run (allow-scripts is not given). allow-same-origin
                     only lets this page measure the email to fit the frame to its height. --}}
                <iframe
                    wire:key="email-preview-{{ $previewEmail }}"
                    sandbox="allow-same-origin"
                    srcdoc="{{ $this->getPreviewHtml() }}"
                    onload="this.style.height = this.contentDocument.documentElement.scrollHeight + 'px'"
                    class="w-full rounded-lg bg-white ring-1 ring-gray-950/10 dark:ring-white/20"
                    style="min-height: 40rem; border: 0;"
                ></iframe>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
