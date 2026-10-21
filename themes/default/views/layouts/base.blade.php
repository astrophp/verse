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
<script>{!! $__verse->inline('src/head-theme.js') !!}</script>
@verseAssets
@if ($layout->accentCss !== null)
<style>{!! $layout->accentCss !!}</style>
@endif
</head>
<body>
<a class="skip-link" href="#main-content">Skip to content</a>
<x-header :layout="$layout" />
<main id="main-content" class="main shell" tabindex="-1">
@yield('content')
</main>
<x-footer :layout="$layout" />
</body>
</html>
