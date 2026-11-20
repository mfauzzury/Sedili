@php
    $livewire ??= null;
    $company = app(\App\Services\CompanyProfile::class)->name() ?? config('app.name');
@endphp

{{-- Sign-in layout: the form on one side, a quiet animated panel on the other (hidden on small screens) --}}
<x-filament-panels::layout.base :livewire="$livewire">
    <div class="auth-split">
        <div class="auth-form-ctn">
            <main id="fi-main-content" tabindex="-1" class="auth-form">
                {{ $slot }}
            </main>
        </div>

        <aside class="auth-visual" aria-hidden="true">
            <div class="auth-blob auth-blob-1"></div>
            <div class="auth-blob auth-blob-2"></div>

            <svg class="auth-graphic" viewBox="-300 -300 600 600" role="presentation" focusable="false">
                <defs>
                    <radialGradient id="auth-core" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="var(--primary-300)" stop-opacity="0.75" />
                        <stop offset="100%" stop-color="var(--primary-300)" stop-opacity="0" />
                    </radialGradient>
                </defs>

                {{-- Rings (static) --}}
                <circle class="auth-ring" r="70" />
                <circle class="auth-ring" r="130" />
                <circle class="auth-ring" r="190" />
                <circle class="auth-ring" r="250" />

                {{-- Each group turns slowly: a line from the centre to a node, like a reporting line --}}
                <g class="auth-orbit auth-orbit-1">
                    <line x1="0" y1="0" x2="70" y2="0" class="auth-spoke" />
                    <circle cx="70" cy="0" r="6" class="auth-node" />
                </g>
                <g class="auth-orbit auth-orbit-2">
                    <line x1="0" y1="0" x2="130" y2="0" class="auth-spoke" />
                    <circle cx="130" cy="0" r="8" class="auth-node" />
                    <circle cx="-130" cy="0" r="5" class="auth-node auth-node-dim" />
                </g>
                <g class="auth-orbit auth-orbit-3">
                    <line x1="0" y1="0" x2="190" y2="0" class="auth-spoke" />
                    <circle cx="190" cy="0" r="9" class="auth-node" />
                    <circle cx="0" cy="-190" r="5" class="auth-node auth-node-dim" />
                    <circle cx="-190" cy="0" r="6" class="auth-node" />
                </g>
                <g class="auth-orbit auth-orbit-4">
                    <line x1="0" y1="0" x2="250" y2="0" class="auth-spoke" />
                    <circle cx="250" cy="0" r="6" class="auth-node auth-node-dim" />
                    <circle cx="-250" cy="0" r="9" class="auth-node" />
                </g>

                {{-- The core --}}
                <circle class="auth-core-glow" r="64" fill="url(#auth-core)" />
                <circle class="auth-core" r="22" />
            </svg>

            <div class="auth-visual-text">
                <div class="auth-visual-name">{{ $company }}</div>
                <p>People, leave and work, in one place.</p>
            </div>
        </aside>
    </div>
</x-filament-panels::layout.base>
