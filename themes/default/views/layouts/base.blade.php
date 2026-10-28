<!doctype html>
<html lang="{{ $layout->language }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
@if ($layout->description !== null)
<meta name="description" content="{{ $layout->description }}">
@endif
<meta name="theme-color" content="#fcfcfc">
<meta name="color-scheme" content="light dark">
<title>{{ $layout->title }}</title>
@if ($layout->canonical !== null)
<link rel="canonical" href="{{ $layout->canonical }}">
<meta property="og:url" content="{{ $layout->canonical }}">
@endif
@if ($layout->feedUrl !== null)
<link rel="alternate" type="application/atom+xml" title="{{ $layout->brandName }}" href="{{ $layout->feedUrl }}">
@endif
<meta property="og:title" content="{{ $layout->pageTitle }}">
@if ($layout->description !== null)
<meta property="og:description" content="{{ $layout->description }}">
@endif
<meta property="og:type" content="{{ $layout->ogType }}">
@if ($layout->ogImage !== null)
<meta property="og:image" content="{{ $layout->ogImage }}">
@endif
@if ($layout->noindex)
<meta name="robots" content="noindex">
@endif
<link rel="icon" href="{{ $layout->iconUrl ?? $layout->monogramIcon }}">
<script>{!! verse()->inline('src/head-theme.js') !!}</script>
@verseAssets
@if ($layout->accentCss !== null)
<style>{!! $layout->accentCss !!}</style>
@endif
@verseHook('head')
</head>
<body>
@verseHook('body-start')
<a class="skip-link" href="#main-content">Skip to content</a>
<header class="site-header" id="site-header">
  <div class="header-inner shell">
    <a class="brand" href="{{ $layout->homeUrl }}" aria-label="{{ $layout->brandLabel }}"><span class="brand-name">{{ $layout->brandName }}</span>@if ($layout->brandRole !== null)<span class="brand-role">{{ $layout->brandRole }}</span>@endif</a>
    <nav class="header-nav" aria-label="Main navigation">
@foreach ($layout->nav as $item)
      <a class="nav-link" href="{{ $item->url }}"@if ($item->current) aria-current="page"@endif>{{ $item->label }}</a>
@endforeach
      <button class="search-button" type="button" aria-label="Search" title="Search (Ctrl or ⌘ K)" data-open-search data-search-index="{{ verse()->asset('search.json') }}" hidden>{!! verse()->icon('search') !!}</button>
      <div class="theme-menu"><button class="header-theme" type="button" id="theme-button" aria-haspopup="menu" aria-expanded="false" aria-controls="theme-options" aria-label="Color theme: System" title="Color theme" hidden>{!! verse()->icon('theme-system') !!}</button><div class="theme-options" id="theme-options" role="menu" aria-label="Color theme" hidden>
@foreach (['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $option => $label)
<button class="theme-option" type="button" role="menuitemradio" aria-checked="false" data-theme-option="{{ $option }}" tabindex="-1">{!! verse()->icon('theme-'.$option) !!}<span>{{ $label }}</span>{!! verse()->icon('check', 'small theme-check') !!}</button>
@endforeach
</div></div>
    </nav>
    <div class="reading-progress" id="reading-progress" aria-hidden="true"></div>
  </div>
</header>
<main id="main-content" class="main shell" tabindex="-1">
@verseHook('main-start')
@yield('content')
@verseHook('main-end')
</main>
<footer class="site-footer shell">
  <div class="footer-inner">
    <div>{{ $layout->copyright }}</div>
@if ($layout->social !== [])
    <div class="footer-right">
      <div class="footer-social">
@foreach ($layout->social as $social)
<a href="{{ $social->url }}" target="_blank" rel="noopener noreferrer">{{ $social->label }}</a>
@endforeach
      </div>
    </div>
@endif
  </div>
  <div class="footer-credit">Built with <a href="https://github.com/astrophp/verse" rel="noopener">Verse</a></div>
</footer>
@verseHook('body-end')
</body>
</html>
