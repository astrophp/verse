@extends('verse::layouts.base')
@section('content')
<div class="not-found">
<h1>A page still unwritten.</h1>
<p>There’s nothing at this address. Let’s find you something to read.</p>
<a class="text-link" href="{{ trim($link->url) }}"><span>{{ $link->label }}</span>{!! $__verse->icon('arrow', '') !!}</a>
</div>
@endsection
