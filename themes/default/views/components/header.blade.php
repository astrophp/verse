@props(['layout'])
<header class="site-header" id="site-header">
  <div class="header-inner shell">
    <a class="brand" href="{{ $layout->homeUrl }}" aria-label="{{ $layout->brandLabel }}"><span class="brand-name">{{ $layout->brandName }}</span>@if ($layout->brandRole !== null)<span class="brand-role">{{ $layout->brandRole }}</span>@endif</a>
    <nav class="header-nav" aria-label="Main navigation">
@foreach ($layout->nav as $item)
      <a class="nav-link" href="{{ $item->url }}"@if ($item->current) aria-current="page"@endif>{{ $item->label }}</a>
@endforeach
      <button class="search-button" type="button" aria-label="Search" title="Search (Ctrl or ⌘ K)" data-open-search data-search-index="{{ $__verse->asset('search.json') }}" hidden>{!! $__verse->icon('search') !!}</button>
      <div class="theme-menu"><button class="header-theme" type="button" id="theme-button" aria-haspopup="menu" aria-expanded="false" aria-controls="theme-options" aria-label="Color theme: System" title="Color theme" hidden>{!! $__verse->icon('theme-system') !!}</button><div class="theme-options" id="theme-options" role="menu" aria-label="Color theme" hidden>
@foreach (['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $option => $label)
<button class="theme-option" type="button" role="menuitemradio" aria-checked="false" data-theme-option="{{ $option }}" tabindex="-1">{!! $__verse->icon('theme-'.$option) !!}<span>{{ $label }}</span>{!! $__verse->icon('check', 'small theme-check') !!}</button>
@endforeach
</div></div>
    </nav>
    <div class="reading-progress" id="reading-progress" aria-hidden="true"></div>
  </div>
</header>
