@props(['title'])
<div class="panel">
<x-card :heading="$title" level="3" class="inner">{{ $slot }}<x-slot:footer>nested footer</x-slot:footer></x-card>
</div>
