@props(['heading', 'level' => 2])
<section {{ $attributes->merge(['class' => 'card']) }}>
<h{{ $level }}>{{ $heading }}</h{{ $level }}>
<div class="card-body">{{ $slot }}</div>
@isset($footer)
<footer class="card-footer">{{ $footer }}</footer>
@endisset
</section>
