@props(['link'])
<a {{ $attributes->merge(['class' => 'back-link', 'href' => $link->url]) }}>{!! $__verse->icon('left-arrow', 'small') !!}<span>{{ $link->label }}</span></a>
