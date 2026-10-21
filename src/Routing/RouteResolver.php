<?php

declare(strict_types=1);

namespace Astro\Verse\Routing;

/**
 * Source locations to routes. Routes are decoded NFC paths that
 * start and end with `/`; encoding happens once, in UrlGenerator.
 */
final class RouteResolver
{
    /**
     * @param  list<string>  $segments  NFC segments of the source location (without `.md`)
     */
    public static function fromSegments(array $segments): string
    {
        return $segments === [] ? '/' : '/'.implode('/', $segments).'/';
    }

    /**
     * `permalink` replaces the whole route, `slug` replaces the last segment.
     *
     * @param  list<string>  $segments
     */
    public static function resolve(array $segments, ?string $slug, ?string $permalink): string
    {
        if ($permalink !== null) {
            return $permalink;
        }

        if ($slug !== null && $segments !== []) {
            $segments[count($segments) - 1] = $slug;
        }

        return self::fromSegments($segments);
    }

    /**
     * Page 1 is the listing route itself; later pages are `<listing>page/<n>/`.
     */
    public static function pageRoute(string $listingRoute, int $number): string
    {
        return $number <= 1 ? $listingRoute : $listingRoute.'page/'.$number.'/';
    }

    /**
     * The tag index, at the site root.
     */
    public static function tagIndexRoute(): string
    {
        return '/tags/';
    }

    public static function tagRoute(string $tagSlug): string
    {
        return '/tags/'.$tagSlug.'/';
    }

    /**
     * `/articles/x/` becomes `articles/x/index.html`; `/` becomes `index.html`.
     */
    public static function outputPath(string $route): string
    {
        return ltrim($route, '/').'index.html';
    }

    /**
     * Output path of a file that lives next to a page's route (an attachment).
     */
    public static function relativeOutputPath(string $route, string $relativePath): string
    {
        return ltrim($route, '/').$relativePath;
    }
}
