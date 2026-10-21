@extends('verse::layouts.base')
@section('content')
<div class="timeline-page{{ $intro->hasContent() ? '' : ' timeline-page-bare' }}">
<h1 class="sr-only">{{ $site->title }}</h1>
@if ($intro->hasContent())
<x-page-intro :intro="$intro" :heading="false" />
@endif
<x-timeline :archive="$archive" />
<div class="archive-more"><a class="button-link" href="{{ $all->url }}">{{ $all->label }} {!! $__verse->icon('arrow') !!}</a></div>
</div>
@endsection
