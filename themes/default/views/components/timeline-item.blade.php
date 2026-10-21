@props(['entry'])
<article class="timeline-item"><div class="timeline-entry">
<div class="timeline-meta"><time datetime="{{ $entry->isoDate }}">{{ $entry->dateLabel }}</time>@if ($entry->stateLabel !== null)<span class="state-badge">{{ $entry->stateLabel }}</span>@endif</div>
<a href="{{ $entry->url }}" class="timeline-article"@if ($entry->external) target="_blank" rel="noopener noreferrer"@endif>
<div class="timeline-title-row"><h2>{{ $entry->title }}</h2><span class="timeline-arrow">{!! $__verse->icon($entry->external ? 'up-right' : 'arrow') !!}</span></div>
@if ($entry->description !== null)
<p>{{ $entry->description }}</p>
@endif
@if ($entry->external)
<span class="sr-only">(opens in a new tab)</span>
@endif
</a>
@if ($entry->tags !== [])
<div class="timeline-topics" aria-label="Article tags"><x-tag-links :tags="$entry->tags" /></div>
@endif
</div></article>
