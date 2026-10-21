@extends('verse::layouts.base')
@section('content')
<div class="not-found">
<h1>A page still unwritten.</h1>
<p>There’s nothing at this address. Let’s find you something to read.</p>
<x-text-link :link="$link" />
</div>
@endsection
