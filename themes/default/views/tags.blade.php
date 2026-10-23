@extends('verse::layouts.base')
@section('content')
<div class="tags-page">
<header class="page-intro tags-intro">
<h1>{{ $intro->title }}</h1>
@if ($rows !== [])
<div class="tag-filter" hidden><label class="sr-only" for="tag-filter-input">Filter tags</label><span class="tag-filter-icon">{!! verse()->icon('search', 'small') !!}</span><input id="tag-filter-input" type="search" placeholder="Filter tags…" autocomplete="off" spellcheck="false"></div>
@endif
</header>
@if ($rows === [])
<p>No tags yet.</p>
@endif
<div class="tag-index">
@foreach ($rows as $row)
<a class="tag-index-row" data-tag-label="{{ $row->label }}" href="{{ $row->url }}"><div><span class="tag-hash" aria-hidden="true">#</span><h2>{{ $row->label }}</h2></div><span class="tag-index-meta">{{ $row->countLabel }} {!! verse()->icon('arrow') !!}</span></a>
@endforeach
</div>
@if ($rows !== [])
<p class="tag-filter-empty" hidden>No tags match.</p>
@endif
</div>
@endsection
