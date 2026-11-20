{{-- Added under the password form of the sign-in page while Microsoft sign-in is set up --}}
<div class="grid gap-y-4">
    <div class="flex items-center gap-x-3 text-xs text-gray-400 dark:text-gray-500">
        <span class="h-px flex-1 bg-gray-200 dark:bg-white/10"></span>
        or
        <span class="h-px flex-1 bg-gray-200 dark:bg-white/10"></span>
    </div>

    <x-filament::button
        tag="a"
        :href="route('microsoft_login.redirect')"
        color="gray"
        outlined
        class="w-full"
    >
        <span class="flex items-center justify-center gap-x-2">
            {{-- The Microsoft logo --}}
            <svg width="16" height="16" viewBox="0 0 21 21" aria-hidden="true" focusable="false">
                <rect x="1" y="1" width="9" height="9" fill="#f25022" />
                <rect x="11" y="1" width="9" height="9" fill="#7fba00" />
                <rect x="1" y="11" width="9" height="9" fill="#00a4ef" />
                <rect x="11" y="11" width="9" height="9" fill="#ffb900" />
            </svg>
            Sign in with Microsoft
        </span>
    </x-filament::button>
</div>
