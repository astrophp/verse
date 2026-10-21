@props(['pagination'])
<nav class="pagination" aria-label="Pagination">
@if ($pagination->newer !== null)
<x-text-link :link="$pagination->newer" :left="true" rel="prev" />
@else
<span class="pagination-disabled">Newer</span>
@endif
<span class="pagination-status">{{ $pagination->status }}</span>
@if ($pagination->older !== null)
<x-text-link :link="$pagination->older" rel="next" />
@else
<span class="pagination-disabled">Older</span>
@endif
</nav>
