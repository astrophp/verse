@extends('verse::layouts.base')
@section('content')
<div class="timeline-page">
<header class="page-intro">
@if ($back !== null)
<a class="back-link tag-back" href="{{ trim($back->url) }}">{!! $__verse->icon('left-arrow', 'small') !!}<span>{{ $back->label }}</span></a>
@endif
@if ($intro->stateLabel !== null)
<p class="state-line"><span class="state-badge">{{ $intro->stateLabel }}</span></p>
@endif
<h1>@if ($intro->tag)<span class="tag-hash" aria-hidden="true">#</span>@endif{{ $intro->title }}</h1>
@if ($intro->description !== null)
<p>{{ $intro->description }}</p>
@endif
@if ($intro->bodyHtml !== null)
{!! $intro->bodyHtml !!}
@endif
</header>
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
<div class="timeline-topics" aria-label="Article tags">@foreach ($entry->tags as $tag)
@if ($tag->url !== null)
<a class="tag-link" href="{{ $tag->url }}"@if ($tag->current) aria-current="true"@endif>{{ $tag->label }}</a>
@else
<span>{{ $tag->label }}</span>
@endif
@endforeach</div>
@endif
</div></article>
@endforeach
</div>
</section>
@empty
<div class="empty-state"><h2>Nothing published yet.</h2></div>
@endforelse
</div>
@if ($archive->pagination !== null)
<nav class="pagination" aria-label="Pagination">
@if ($archive->pagination->newer !== null)
<a class="text-link" href="{{ trim($archive->pagination->newer->url) }}" rel="prev">{!! $__verse->icon('left-arrow') !!}<span>{{ $archive->pagination->newer->label }}</span></a>
@else
<span class="pagination-disabled">Newer</span>
@endif
<span class="pagination-status">{{ $archive->pagination->status }}</span>
@if ($archive->pagination->older !== null)
<a class="text-link" href="{{ trim($archive->pagination->older->url) }}" rel="next"><span>{{ $archive->pagination->older->label }}</span>{!! $__verse->icon('arrow', '') !!}</a>
@else
<span class="pagination-disabled">Older</span>
@endif
</nav>
@endif
</div>
@endsection
