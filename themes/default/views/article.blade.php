@extends('verse::layouts.base')
@section('content')
<div class="article-page">
<div class="article-back"><x-back-link :link="$article->back" /></div>
<header class="article-header">
<h1>{{ $article->title }}</h1>
@if ($article->description !== null)
<p class="article-deck">{{ $article->description }}</p>
@endif
<div class="article-meta">
@if ($article->stateLabel !== null)
<span class="state-badge">{{ $article->stateLabel }}</span>
@endif
@if ($article->isoDate !== null)
<time datetime="{{ $article->isoDate }}">{{ $article->dateLabel }}</time><span class="separator">/</span>
@endif
<span>{{ $article->readingMinutes }} min read</span>
@if ($article->tags !== [])
<span class="separator">/</span><span class="article-topic"><x-tag-links :tags="$article->tags" /></span>
@endif
</div>
</header>
<div class="article-layout">
<div>
@if ($article->toc !== [])
<details class="mobile-toc"><summary>On this page</summary><nav aria-label="Article contents on mobile">
@foreach ($article->toc as $entry)
<a href="#{{ $entry->id }}" data-toc="{{ $entry->id }}"@if ($loop->first) aria-current="true"@endif>{{ $entry->text }}</a>
@endforeach
</nav></details>
@endif
<article class="prose" aria-label="Article content">
{!! $article->bodyHtml !!}
</article>
<div class="article-end"><x-text-link :link="$article->end" /></div>
@if ($article->related !== null)
<div class="related-article"><div class="small-label">Keep reading</div><a href="{{ $article->related->url }}"@if ($article->related->external) target="_blank" rel="noopener noreferrer"@endif><span>{{ $article->related->label }}</span>{!! $__verse->icon($article->related->external ? 'up-right' : 'arrow') !!}</a></div>
@endif
</div>
@if ($article->toc !== [])
<aside class="toc" aria-label="Article contents"><p class="toc-label">On this page</p><nav>
@foreach ($article->toc as $entry)
<a href="#{{ $entry->id }}" data-toc="{{ $entry->id }}"@if ($loop->first) aria-current="true"@endif>{{ $entry->text }}</a>
@endforeach
</nav><div class="toc-footer">{{ $article->readingMinutes }} min read<br>Take your time.</div></aside>
@endif
</div>
</div>
@endsection
