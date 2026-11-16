@php($collapsible = $node['type'] === 'department' && $node['children'])
<li @if ($collapsible) x-data="{ open: {{ ($startOpen ?? false) ? 'true' : 'false' }} }" x-on:org-toggle-all.window="open = $event.detail" @endif>
    @if ($node['type'] === 'department')
        @if ($collapsible)
            <button type="button" class="org-node org-department org-toggle" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()">
                <span class="org-name">{{ $node['name'] }}</span>
                <span class="org-sub">
                    @if ($node['code']){{ $node['code'] }} · @endif{{ $node['count'] }} {{ \Illuminate\Support\Str::plural('person', $node['count']) }}
                </span>
                @if ($node['head'])
                    <span class="org-sub">Head: {{ $node['head'] }}</span>
                @endif
                <x-filament::icon icon="heroicon-m-chevron-down" class="org-chevron" x-bind:class="{ 'org-chevron-open': open }" />
            </button>
        @else
            <div class="org-node org-department">
                <span class="org-name">{{ $node['name'] }}</span>
                <span class="org-sub">
                    @if ($node['code']){{ $node['code'] }} · @endif{{ $node['count'] }} {{ \Illuminate\Support\Str::plural('person', $node['count']) }}
                </span>
                @if ($node['head'])
                    <span class="org-sub">Head: {{ $node['head'] }}</span>
                @endif
            </div>
        @endif
    @else
        <a href="{{ \App\Filament\Resources\Employees\EmployeeResource::getUrl('edit', ['record' => $node['id']]) }}" class="org-node org-person" title="Open {{ $node['name'] }}">
            <span class="org-avatar">{{ collect(explode(' ', $node['name']))->filter()->take(2)->map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') }}</span>
            <span class="org-name">{{ $node['name'] }}</span>
            @if ($node['title'])
                <span class="org-sub">{{ $node['title'] }}</span>
            @endif
        </a>
    @endif
    @if ($node['children'])
        <ul @if ($collapsible) x-show="open" x-cloak @endif>
            @foreach ($node['children'] as $child)
                @include('filament.pages.partials.org-node', ['node' => $child, 'startOpen' => false])
            @endforeach
        </ul>
    @endif
</li>
