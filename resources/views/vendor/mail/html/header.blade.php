@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ rtrim(config('app.url'), '/') }}/mail/logo.png" class="logo" width="32" height="32" alt=""><span class="brand">{!! $slot !!}</span>
</a>
</td>
</tr>
