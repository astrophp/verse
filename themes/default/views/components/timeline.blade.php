@props(['archive'])
@if ($archive->sectionLabel !== null)
<div class="archive-toolbar"><span class="archive-section-label">{{ $archive->sectionLabel }}</span></div>
@endif
@if ($archive->countLabel !== null)
<div class="archive-count">{{ $archive->countLabel }}</div>
@endif
<div class="archive-list">
@forelse ($archive->groups as $group)
<section class="timeline-group" aria-label="Published in {{ $group->fullLabel }}">
<div class="timeline-year">{{ $group->label }}</div>
<div class="timeline-items">
@foreach ($group->entries as $entry)
<x-timeline-item :entry="$entry" />
@endforeach
</div>
</section>
@empty
<div class="empty-state"><h2>Nothing published yet.</h2></div>
@endforelse
</div>
@if ($archive->pagination !== null)
<x-pagination :pagination="$archive->pagination" />
@endif
