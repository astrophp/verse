@props(['intro', 'back' => null, 'heading' => true])
<header class="page-intro">
@if ($back !== null)
<x-back-link :link="$back" class="tag-back" />
@endif
@if ($intro->stateLabel !== null)
<p class="state-line"><span class="state-badge">{{ $intro->stateLabel }}</span></p>
@endif
@if ($heading)
<h1>@if ($intro->tag)<span class="tag-hash" aria-hidden="true">#</span>@endif{{ $intro->title }}</h1>
@endif
@if ($intro->description !== null)
<p>{{ $intro->description }}</p>
@endif
@if ($intro->bodyHtml !== null)
{!! $intro->bodyHtml !!}
@endif
</header>
