@props(['layout'])
<footer class="site-footer shell">
  <div class="footer-inner">
    <div>{{ $layout->copyright }}</div>
@if ($layout->social !== [])
    <div class="footer-right">
      <div class="footer-social">
@foreach ($layout->social as $link)
<a href="{{ $link->url }}" target="_blank" rel="noopener noreferrer">{{ $link->label }}</a>
@endforeach
      </div>
    </div>
@endif
  </div>
  <div class="footer-credit">Built with <a href="https://github.com/astrophp/verse" rel="noopener">Verse</a></div>
</footer>
