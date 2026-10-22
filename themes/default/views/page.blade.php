@extends('verse::layouts.base')
@section('content')
<header class="page-intro">
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
<div class="article-layout"><div>
<article class="prose">
{!! $bodyHtml !!}
</article>
</div></div>
@endsection
