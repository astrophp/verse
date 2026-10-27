<?php

declare(strict_types=1);

namespace Astro\Verse\Site;

use Astro\Verse\Build\BuildContext;
use Astro\Verse\Build\Feed;
use Astro\Verse\Build\SearchIndex;
use Astro\Verse\Build\Sitemap;
use Astro\Verse\Content\ContentScanner;
use Astro\Verse\Content\FrontMatter;
use Astro\Verse\Content\FrontMatterParser;
use Astro\Verse\Content\PageBundle;
use Astro\Verse\Content\PageFile;
use Astro\Verse\Content\PlainDir;
use Astro\Verse\Content\PublicFile;
use Astro\Verse\Content\PublicScanner;
use Astro\Verse\Error\ContentException;
use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Routing\ClaimSource;
use Astro\Verse\Routing\OutputRegistry;
use Astro\Verse\Routing\RouteResolver;
use Astro\Verse\Routing\UrlGenerator;
use Astro\Verse\Support\Unicode;
use Astro\Verse\Support\Warnings;

/**
 * Builds the SiteModel: scan, parse and validate every source, drop what is not eligible
 * (one `$now`), then derive the articles, tags and menu and claim every output path.
 */
final class SiteLoader
{
    public function __construct(
        private readonly ContentScanner $scanner = new ContentScanner,
        private readonly PublicScanner $publicScanner = new PublicScanner,
        private readonly FrontMatterParser $parser = new FrontMatterParser,
    ) {}

    public function load(BuildContext $context, Warnings $warnings): SiteModel
    {
        $tree = $this->scanner->scan($context->root, $warnings);
        $publicFiles = $this->publicScanner->scan($context->root);

        /** @var list<string> $excluded */
        $excluded = [];

        /** @var list<Page> $standalone */
        $standalone = [];
        $this->walk($tree->children, $context, $standalone, $excluded);

        $items = [];
        foreach ($tree->articles as $source) {
            $page = $this->read($source, true, $context);
            if ($this->isEligible($page, $context)) {
                $items[] = $page;
            } else {
                $excluded[] = $page->sourcePath;
            }
        }
        $articles = ArticlesBuilder::build($items);

        $pages = [...$standalone, ...$articles->items];
        $menu = $this->menu($pages);

        $registry = new OutputRegistry;
        $this->claimOutputs($registry, $context, $standalone, $articles, $publicFiles);

        return new SiteModel(
            context: $context,
            pages: $pages,
            articles: $articles,
            menu: $menu,
            publicFiles: $publicFiles,
            registry: $registry,
            urls: UrlGenerator::forContext($context),
            excludedSources: $excluded,
        );
    }

    /**
     * @param  list<PageFile|PageBundle|PlainDir>  $children
     * @param  list<Page>  $standalone
     * @param  list<string>  $excluded  receives the source paths of pages dropped as not eligible
     */
    private function walk(array $children, BuildContext $context, array &$standalone, array &$excluded): void
    {
        foreach ($children as $child) {
            if ($child instanceof PlainDir) {
                $this->walk($child->children, $context, $standalone, $excluded);
            } else {
                $page = $this->read($child, false, $context);
                if ($this->isEligible($page, $context)) {
                    $standalone[] = $page;
                } else {
                    $excluded[] = $page->sourcePath;
                }
            }
        }
    }

    private function read(PageFile|PageBundle $source, bool $article, BuildContext $context): Page
    {
        $path = $source->path;
        $text = file_get_contents($context->root.'/'.$path);
        if ($text === false) {
            throw new FilesystemException('cannot read the file.', $path);
        }

        $document = $this->parser->parse($text, $path, $context->config->dateTimeZone());
        $meta = $document->frontMatter;

        $role = $this->validatePlacement($meta, $path, $article, $source instanceof PageBundle);

        $route = $meta->isExternal() ? null : RouteResolver::resolve($source->segments, $meta->slug, $meta->permalink);

        return new Page(
            sourcePath: $path,
            bundleDirectory: $source instanceof PageBundle ? $source->directory : null,
            meta: $meta,
            role: $role,
            route: $route,
            attachments: $source instanceof PageBundle ? $source->attachments : [],
            body: $document->body,
            state: $this->state($meta, $context),
        );
    }

    private function validatePlacement(FrontMatter $meta, string $path, bool $article, bool $bundle): PageRole
    {
        if ($meta->isExternal()) {
            if (! $article) {
                throw new ContentException(
                    '"external_url" is only valid for articles. Move the file into content/articles/, or remove the field.',
                    $path,
                    'external_url',
                );
            }
            if ($bundle) {
                throw new ContentException(
                    'external articles have no page, so bundle attachments would be unreachable. Use a single file (name.md) instead of a bundle.',
                    $path,
                    'external_url',
                );
            }
            if ($meta->menu) {
                throw new ContentException(
                    'external articles have no local page, so they cannot be in the main menu. Remove "menu: true".',
                    $path,
                    'menu',
                );
            }
        }

        if ($article && $meta->date === null) {
            throw new ContentException(
                'articles need a date (listings are timelines). Add `date: YYYY-MM-DD`.',
                $path,
                'date',
            );
        }

        return $article ? PageRole::Article : PageRole::Page;
    }

    private function state(FrontMatter $meta, BuildContext $context): PublicationState
    {
        if ($meta->draft) {
            return PublicationState::Draft;
        }

        return $meta->date !== null && $meta->date > $context->now
            ? PublicationState::Scheduled
            : PublicationState::Published;
    }

    private function isEligible(Page $page, BuildContext $context): bool
    {
        return $context->drafts || ! $page->isUnpublished();
    }

    /**
     * "Home", "Articles" and "Tags" come first, then the pages that ask for a menu entry (articles never add one).
     *
     * @param  list<Page>  $pages
     */
    private function menu(array $pages): Menu
    {
        $items = [];
        foreach ($pages as $page) {
            if ($page->role === PageRole::Page && $page->meta->menu && $page->route !== null) {
                $items[] = new MenuItem($page->meta->title, $page->route, $page->meta->order);
            }
        }

        return Menu::of($items, [
            new MenuItem('Home', '/', null),
            new MenuItem(Articles::TITLE, Articles::ROUTE, null),
            new MenuItem('Tags', RouteResolver::tagIndexRoute(), null),
        ]);
    }

    /**
     * @param  list<Page>  $standalone
     * @param  list<PublicFile>  $publicFiles
     */
    private function claimOutputs(
        OutputRegistry $registry,
        BuildContext $context,
        array $standalone,
        Articles $articles,
        array $publicFiles,
    ): void {
        $registry->claim('404.html', 'the 404 page', ClaimSource::System);
        $registry->claim(SearchIndex::PATH, 'the search index', ClaimSource::System);
        if ($context->publishesSitemap()) {
            $registry->claim(Sitemap::PATH, 'the sitemap', ClaimSource::System);
        }
        if ($context->publishesFeed()) {
            $registry->claim(Feed::PATH, 'the feed', ClaimSource::System);
        }
        $registry->claim('.verse-output', 'the Verse output marker', ClaimSource::System);

        $registry->claim('index.html', 'the home page');
        foreach ($articles->listing->pages as $listingPage) {
            $registry->claim(
                RouteResolver::outputPath($listingPage->route),
                'listing page '.$listingPage->number.' of the articles',
            );
        }

        foreach ($standalone as $page) {
            $this->claimPage($registry, $page);
        }
        foreach ($articles->items as $item) {
            $this->claimPage($registry, $item);
        }

        $registry->claim(RouteResolver::outputPath(RouteResolver::tagIndexRoute()), 'the tag index');
        foreach ($articles->tags as $tag) {
            foreach ($tag->listing->pages as $tagPage) {
                $registry->claim(
                    RouteResolver::outputPath($tagPage->route),
                    'tag archive "'.$tag->label.'" (page '.$tagPage->number.')',
                );
            }
        }

        foreach ($publicFiles as $file) {
            $registry->claim($file->outputPath, 'public file '.$file->path);
        }
    }

    private function claimPage(OutputRegistry $registry, Page $page): void
    {
        if ($page->route === null) {
            return;
        }

        $registry->claim(RouteResolver::outputPath($page->route), 'page '.$page->sourcePath);
        foreach ($page->attachments as $attachment) {
            $registry->claim(
                RouteResolver::relativeOutputPath($page->route, Unicode::nfc($attachment->relativePath)),
                'attachment '.$attachment->path,
            );
        }
    }
}
