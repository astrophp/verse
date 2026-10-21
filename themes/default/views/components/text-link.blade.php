@props(['link', 'left' => false])
<a {{ $attributes->merge(['class' => 'text-link', 'href' => $link->url]) }}@if ($link->external) target="_blank" rel="noopener noreferrer"@endif>@if ($left){!! $__verse->icon('left-arrow') !!}@endif<span>{{ $link->label }}</span>@if (! $left){!! $__verse->icon($link->external ? 'up-right' : 'arrow', $link->external ? 'small' : '') !!}@endif</a>
