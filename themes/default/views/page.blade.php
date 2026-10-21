@extends('verse::layouts.base')
@section('content')
<x-page-intro :intro="$intro" />
<div class="article-layout"><div>
<article class="prose">
{!! $bodyHtml !!}
</article>
</div></div>
@endsection
