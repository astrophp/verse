<?php

declare(strict_types=1);

namespace Astro\Verse\View;

use Astro\Verse\Error\ContentException;
use Astro\Verse\Error\VerseException;
use Astro\Verse\Markdown\RenderedMarkdown;
use Astro\Verse\Routing\RouteResolver;
use Astro\Verse\Site\Articles;
use Astro\Verse\Site\ListingPage;
use Astro\Verse\Site\Page;
use Astro\Verse\Site\PageRole;
use Astro\Verse\Site\SiteMarkdown;
use Astro\Verse\Site\SiteModel;
use Astro\Verse\Site\Tag;
use Astro\Verse\Support\Unicode;
use Astro\Verse\Theme\Theme;
use Astro\Verse\View\Model\ArticleView;
use Astro\Verse\View\Model\Intro;
use Astro\Verse\View\Model\Link;
use Astro\Verse\View\Model\TagRow;
use Astro\Verse\View\Model\TocEntry;
use Generator;
use Illuminate\Container\Container;
use Illuminate\View\Factory;
use Throwable;

/**
 * Renders every output page of a site with a typed context: the home page, standalone pages,
 * the article archive, articles, tag index and archives, and the 404 page.
 *
 * Markdown bodies are rendered by SiteMarkdown and printed by the views exactly once, unescaped; they never
 * pass through Blade's compiler.
 */
final class SiteRenderer
{
    private readonly SiteMarkdown $markdown;

    private readonly LayoutBuilder $layouts;

    private readonly TimelineBuilder $timelines;

    private Factory $views;

    /** @var array<string, RenderedMarkdown> rendered bodies by source path */
    private array $rendered = [];

    public function __construct(
        private readonly SiteModel $site,
        private readonly Theme $theme,
        private readonly ThemeContext $context,
        private readonly string $cachePath,
        private readonly ?string $hooksPath = null,
    ) {
        $this->markdown = new SiteMarkdown($site);
        $this->layouts = new LayoutBuilder($site, $context);
        $this->timelines = new TimelineBuilder($context);
    }

    /**
     * @return Generator<string, string> HTML by output path (relative to the output directory)
     */
    public function render(): Generator
    {
        $this->views = BladeFactory::create($this->theme->viewsPath, $this->cachePath, $this->hooksPath);
        $container = Container::getInstance();
        $container->instance(ThemeContext::class, $this->context);
        try {
            yield from $this->pages();
        } finally {
            // verse() works only while this site renders
            $container->forgetInstance(ThemeContext::class);
        }
    }

    /**
     * @return Generator<string, string>
     */
    private function pages(): Generator
    {

        yield 'index.html' => $this->home();

        foreach ($this->site->pages as $page) {
            if ($page->role === PageRole::Page) {
                yield RouteResolver::outputPath((string) $page->route) => $this->standalone($page);
            }
        }

        $articles = $this->site->articles;
        foreach ($articles->listing->pages as $page) {
            yield RouteResolver::outputPath($page->route) => $this->listing($articles, $page);
        }
        foreach ($articles->items as $index => $item) {
            if ($item->route !== null) {
                yield RouteResolver::outputPath($item->route) => $this->article($articles, $item, $articles->items[$index + 1] ?? null);
            }
        }

        yield RouteResolver::outputPath(RouteResolver::tagIndexRoute()) => $this->tagIndex($articles);
        foreach ($articles->tags as $tag) {
            foreach ($tag->listing->pages as $page) {
                yield RouteResolver::outputPath($page->route) => $this->tagArchive($articles, $tag, $page);
            }
        }

        yield '404.html' => $this->notFound();
    }

    private function home(): string
    {
        $config = $this->site->context->config;
        $articles = $this->site->articles;

        // the heading is hidden; a visible introduction exists only when the site has a description
        $intro = new Intro($config->title, self::text($config->description));
        $layout = $this->layouts->build($config->title, true, null, $this->context->canonical('/'), '/');

        return $this->view('home', [
            'layout' => $layout,
            'intro' => $intro,
            'archive' => $this->timelines->latest($articles->latest(), 'Latest articles'),
            'all' => new Link('All '.mb_strtolower(Articles::TITLE), $this->context->url(Articles::ROUTE)),
        ], articles: $articles);
    }

    private function standalone(Page $page): string
    {
        $route = (string) $page->route;
        $layout = $this->layouts->build(
            $page->meta->title,
            false,
            $page->meta->description,
            $page->meta->canonical ?? $this->context->canonical($route),
            $route,
            ogImage: $this->imageUrl($page),
            noindex: $page->isUnpublished(),
        );

        return $this->view('page', [
            'layout' => $layout,
            'intro' => new Intro($page->meta->title, self::text($page->meta->description), stateLabel: TimelineBuilder::stateLabel($page)),
            'bodyHtml' => $this->body($page)->html,
        ], $page);
    }

    private function listing(Articles $articles, ListingPage $page): string
    {
        $layout = $this->layouts->build(
            Articles::TITLE,
            false,
            self::text($this->site->context->config->description),
            $this->context->canonical($page->route),
            Articles::ROUTE,
        );

        return $this->view('listing', [
            'layout' => $layout,
            'intro' => new Intro(Articles::TITLE),
            'back' => null,
            'archive' => $this->timelines->archive($page, 'All articles'),
        ], articles: $articles);
    }

    private function tagArchive(Articles $articles, Tag $tag, ListingPage $page): string
    {
        $intro = new Intro($tag->label, tag: true);
        $layout = $this->layouts->build(
            $tag->label,
            false,
            self::text($this->site->context->config->description),
            $this->context->canonical($page->route),
            RouteResolver::tagIndexRoute(),
        );

        return $this->view('listing', [
            'layout' => $layout,
            'intro' => $intro,
            'back' => new Link('All tags', $this->context->url(RouteResolver::tagIndexRoute())),
            'archive' => $this->timelines->archive($page, null, $tag->slug),
        ], articles: $articles);
    }

    private function tagIndex(Articles $articles): string
    {
        $intro = new Intro('Tags');

        $rows = [];
        foreach ($articles->tags as $tag) {
            $rows[] = new TagRow(
                $tag->label,
                $this->context->url($tag->route),
                $tag->count().($tag->count() === 1 ? ' article' : ' articles'),
            );
        }

        $route = RouteResolver::tagIndexRoute();
        $layout = $this->layouts->build('Tags', false, self::text($this->site->context->config->description), $this->context->canonical($route), RouteResolver::tagIndexRoute());

        return $this->view('tags', [
            'layout' => $layout,
            'intro' => $intro,
            'back' => null,
            'rows' => $rows,
        ], articles: $articles);
    }

    private function article(Articles $articles, Page $page, ?Page $older): string
    {
        $route = (string) $page->route;
        $body = $this->body($page);
        $date = $page->meta->date;
        $name = mb_strtolower(Articles::TITLE);
        $canonical = $page->meta->canonical ?? $this->context->canonical($route);

        $toc = [];
        if ($this->site->context->config->theme->toc && count($body->headings) >= 2) {
            foreach ($body->headings as $heading) {
                $toc[] = new TocEntry($heading->id, $heading->text);
            }
        }

        $article = new ArticleView(
            title: $page->meta->title,
            description: self::text($page->meta->description),
            back: new Link('Back to '.$name, $this->context->url(Articles::ROUTE)),
            isoDate: $date === null ? null : $this->context->isoDate($date),
            dateLabel: $date === null ? null : $this->context->formatDate($date, 'd MMMM y'),
            stateLabel: TimelineBuilder::stateLabel($page),
            readingMinutes: $body->readingMinutes(),
            tags: $this->timelines->tags($page),
            toc: $toc,
            bodyHtml: $body->html,
            end: $this->isOffSite($page->meta->canonical)
                ? new Link('Read the original', (string) $page->meta->canonical, external: true)
                : new Link('Back to '.$name, $this->context->url(Articles::ROUTE)),
            related: $this->olderLink($older),
        );

        $layout = $this->layouts->build(
            $page->meta->title,
            false,
            $page->meta->description,
            $canonical,
            Articles::ROUTE,
            'article',
            $this->imageUrl($page),
            $page->isUnpublished(),
        );

        return $this->view('article', ['layout' => $layout, 'article' => $article], $page, $articles);
    }

    private function notFound(): string
    {
        $link = new Link('Back to '.mb_strtolower(Articles::TITLE), $this->context->url(Articles::ROUTE));

        return $this->view('not-found', [
            'layout' => $this->layouts->build('Page not found', false, null, null, ''),
            'link' => $link,
        ]);
    }

    /**
     * The link to the next older article (external articles link out).
     */
    private function olderLink(?Page $older): ?Link
    {
        return $older === null
            ? null
            : new Link(
                $older->meta->title,
                $older->route === null ? (string) $older->meta->externalUrl : $this->context->url($older->route),
                external: $older->route === null,
            );
    }

    private function isOffSite(?string $canonical): bool
    {
        if ($canonical === null) {
            return false;
        }
        $origin = $this->site->context->config->url?->origin;
        $parts = parse_url($canonical);
        $canonicalOrigin = strtolower(($parts['scheme'] ?? '').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : ''));

        return $origin === null || $canonicalOrigin !== $origin;
    }

    /**
     * Absolute `og:image` URL; null without `image` or without a configured `url`.
     */
    private function imageUrl(Page $page): ?string
    {
        $image = $page->meta->image;
        $origin = $this->site->context->config->url?->origin;
        if ($image === null || $origin === null) {
            return null;
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $image) === 1) {
            return $image;
        }
        if (str_starts_with($image, '/')) {
            return $origin.$this->context->asset(ltrim($image, '/'));
        }

        $relative = Unicode::nfc(preg_replace('~^(\./)+~', '', $image) ?? $image);
        foreach ($page->attachments as $attachment) {
            if (Unicode::nfc($attachment->relativePath) === $relative && $page->route !== null) {
                return $origin.$this->context->asset(RouteResolver::relativeOutputPath($page->route, $relative));
            }
        }

        throw new ContentException(
            'the image "'.$image.'" is not a file of this page bundle. Put the file next to page.md, or use a /site-root path or an absolute URL.',
            $page->sourcePath,
            'image',
        );
    }

    private static function text(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }

    private function body(Page $page): RenderedMarkdown
    {
        return $this->rendered[$page->sourcePath] ??= $this->markdown->page($page);
    }

    /**
     * Renders a role view. Besides the role's own view models every view receives the whole context:
     * `$site` (the configuration), `$theme` (resolved settings), `$page` (null for generated
     * pages), `$articles` (null for pages and the 404 page) and `$menu`; `verse()` is the theme helper.
     *
     * @param  array<string, mixed>  $data
     */
    private function view(string $name, array $data, ?Page $page = null, ?Articles $articles = null): string
    {
        $config = $this->site->context->config;
        $context = [
            'site' => $config,
            'theme' => $config->theme,
            'page' => $page,
            'articles' => $articles,
            'menu' => $this->site->menu,
        ];

        try {
            return $this->views->make('verse::'.$name, $context + $data)->render();
        } catch (Throwable $exception) {
            // Blade wraps what a view throws; the build reports the original error
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof VerseException) {
                    throw $cause;
                }
            }

            throw $exception;
        }
    }
}
