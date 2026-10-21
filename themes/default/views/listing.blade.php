@extends('verse::layouts.base')
@section('content')
<div class="timeline-page">
<x-page-intro :intro="$intro" :back="$back" />
<x-timeline :archive="$archive" />
</div>
@endsection
