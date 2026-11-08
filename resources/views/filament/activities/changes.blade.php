@php
    $new = $activity->properties['attributes'] ?? [];
    $old = $activity->properties['old'] ?? [];
    $fields = array_unique(array_merge(array_keys($new), array_keys($old)));
    $show = fn ($value) => $value === null ? '—' : (is_scalar($value) ? (string) $value : json_encode($value));
@endphp

<div class="space-y-3 text-sm">
    <p class="text-gray-500 dark:text-gray-400">
        {{ $activity->created_at->format('d M Y H:i') }} by {{ $activity->causer?->name ?? 'System' }}
    </p>
    <table class="w-full text-left">
        <thead>
            <tr class="text-gray-500 dark:text-gray-400">
                <th class="py-1 pe-3">Field</th>
                <th class="py-1 pe-3">Before</th>
                <th class="py-1">After</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($fields as $field)
                <tr class="border-t border-gray-200 dark:border-white/10">
                    <td class="py-1 pe-3 font-medium">{{ $field }}</td>
                    <td class="py-1 pe-3 break-all">{{ $show($old[$field] ?? null) }}</td>
                    <td class="py-1 break-all">{{ $show($new[$field] ?? null) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
