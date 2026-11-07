@props(['url', 'logo' => null])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($logo)
<img src="{{ $logo }}" alt="{{ strip_tags($slot) }}" style="height: 40px; width: auto; max-width: 220px; margin: 15px 0 10px;">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
