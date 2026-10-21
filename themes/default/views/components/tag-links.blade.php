@props(['tags'])
@foreach ($tags as $tag)
@if ($tag->url !== null)
<a class="tag-link" href="{{ $tag->url }}"@if ($tag->current) aria-current="true"@endif>{{ $tag->label }}</a>
@else
<span>{{ $tag->label }}</span>
@endif
@endforeach
