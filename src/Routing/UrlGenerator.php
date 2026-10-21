<?php

declare(strict_types=1);

namespace Astro\Verse\Routing;

use Astro\Verse\Build\BuildContext;

/**
 * The one URL contract: routes and output paths are held decoded (NFC) and
 * encoded here exactly once, segment by segment, with rawurlencode.
 */
final readonly class UrlGenerator
{
    /**
     * @param  string  $basePath  decoded path starting and ending in `/`
     * @param  ?string  $origin  e.g. `https://example.com`; null when no `url` is configured
     */
    public function __construct(
        private string $basePath = '/',
        private ?string $origin = null,
    ) {}

    public static function forContext(BuildContext $context): self
    {
        return new self($context->basePath, $context->config->url?->origin);
    }

    /**
     * Link to a page route (base path included).
     */
    public function page(string $route): string
    {
        return self::encode($this->basePath).self::encode(ltrim($route, '/'));
    }

    /**
     * Link to a file in the output (theme asset, attachment, public file), e.g. `assets/theme/app.css`.
     */
    public function asset(string $path): string
    {
        return self::encode($this->basePath).self::encode(ltrim($path, '/'));
    }

    /**
     * Absolute production URL of a route; null when no `url` is configured (preview).
     */
    public function canonical(string $route): ?string
    {
        return $this->origin === null ? null : $this->origin.$this->page($route);
    }

    /**
     * Link inside the preview server: same as a page link; the origin is the browser's.
     */
    public function preview(string $route): string
    {
        return $this->page($route);
    }

    private static function encode(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
