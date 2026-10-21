@extends('verse::layouts.base')
@section('title', $title)
@section('content')
<x-card heading="Escaped &amp; <b>" class="extra" data-id="7">
Body {{ $untrusted }} / {!! $trusted !!}
<x-slot:footer>Footer slot</x-slot:footer>
</x-card>
<x-panel title="Nested">inside panel</x-panel>
@endsection
@section('footer')
Custom footer
@endsection
