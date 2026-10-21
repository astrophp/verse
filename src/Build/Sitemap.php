<?php

declare(strict_types=1);

namespace Astro\Verse\Build;

use Astro\Verse\Routing\RouteResolver;
use Astro\Verse\Site\Page;
use Astro\Verse\Site\PageRole;
use Astro\Verse\Site\SiteModel;
use DateTimeImmutable;
use LogicException;

/**
 * The production `sitemap.xml`: the home page, standalone pages, every archive page, local
 * articles, the tag index and every tag archive page, in rendering order, as absolute URLs. Left out: drafts and
 * scheduled pages (never in a production site model), external articles (they have no page) and pages whose
 * `canonical` points to another host. `lastmod` is the page's `date`, nothing else.
 */
final readonly class Sitemap
{
    public const string PATH = 'sitemap.xml';

    public function __construct(private SiteModel $site) {}

    public function xml(): string
    {
        $articles = $this->site->articles;

        /** @var list<array{route: string, page: ?Page}> $entries */
        $entries = [['route' => '/', 'page' => null]];
        foreach ($this->site->pages as $page) {
            if ($page->role === PageRole::Page) {
                $entries[] = ['route' => (string) $page->route, 'page' => $page];
            }
        }
        foreach ($articles->listing->pages as $listingPage) {
            $entries[] = ['route' => $listingPage->route, 'page' => null];
        }
        foreach ($articles->items as $item) {
            $entries[] = ['route' => (string) $item->route, 'page' => $item];
        }
        $entries[] = ['route' => RouteResolver::tagIndexRoute(), 'page' => null];
        foreach ($articles->tags as $tag) {
            foreach ($tag->listing->pages as $tagPage) {
                $entries[] = ['route' => $tagPage->route, 'page' => null];
            }
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($entries as ['route' => $route, 'page' => $page]) {
            if ($page !== null && ! $this->listed($page)) {
                continue;
            }
            $xml .= "  <url>\n    <loc>".self::escape($this->location($route))."</loc>\n";
            $lastModified = $page === null ? null : $this->lastModified($page);
            if ($lastModified !== null) {
                $xml .= '    <lastmod>'.self::escape($lastModified)."</lastmod>\n";
            }
            $xml .= "  </url>\n";
        }

        return $xml."</urlset>\n";
    }

    private function listed(Page $page): bool
    {
        if ($page->route === null || $page->isUnpublished()) {
            return false;
        }

        $canonical = $page->meta->canonical;
        if ($canonical === null) {
            return true;
        }

        $url = $this->site->context->config->url ?? throw self::missingUrl();

        return strtolower((string) parse_url($canonical, PHP_URL_HOST)) === $url->host();
    }

    private function location(string $route): string
    {
        return $this->site->urls->canonical($route) ?? throw self::missingUrl();
    }

    private function lastModified(Page $page): ?string
    {
        $date = $page->meta->date;

        return $date === null
            ? null
            : DateTimeImmutable::createFromInterface($date)->setTimezone($this->site->context->config->dateTimeZone())->format(DATE_W3C);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function missingUrl(): LogicException
    {
        return new LogicException('a sitemap needs the configured `url`; production builds always have one.');
    }
}
