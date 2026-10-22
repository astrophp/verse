<?php

declare(strict_types=1);

namespace Astro\Verse\View;

use Astro\Verse\Assets\ViteManifest;
use Astro\Verse\Error\ThemeException;
use Astro\Verse\Routing\UrlGenerator;
use Astro\Verse\Site\MenuItem;
use Astro\Verse\Site\SiteModel;
use Astro\Verse\Theme\Theme;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use Locale;

/**
 * The `$__verse` helper shared with every view: URLs, asset tags, inlined theme files, icons, dates and menu
 * state. Everything it prints unescaped (asset tags, icons, inlined files) comes from the theme itself,
 * never from site content.
 */
final class ThemeContext
{
    /** @var array<string, string> SVG content by icon name */
    private const array ICONS = [
        'arrow' => '<path d="M4 12h15m-6-6 6 6-6 6"/>',
        'up-right' => '<path d="M6 18 18 6M6 6h12v12"/>',
        'left-arrow' => '<path d="M20 12H5m6 6-6-6 6-6"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'search' => '<circle cx="10.7" cy="10.7" r="6.7"/><path d="m16 16 4.5 4.5"/>',
        'theme-light' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/>',
        'theme-dark' => '<path d="M20.8 13.1A8.7 8.7 0 0 1 10.9 3.2a8.8 8.8 0 1 0 9.9 9.9Z"/>',
        'theme-system' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8m-4-4v4"/>',
    ];

    private readonly UrlGenerator $urls;

    private readonly DateTimeZone $timezone;

    private readonly string $locale;

    /** @var array<string, IntlDateFormatter> */
    private array $formatters = [];

    /** @var array<string, string> inlined file contents by the path the view asked for; a context lives for one build */
    private array $inlineFilesCache = [];

    public function __construct(
        private readonly SiteModel $site,
        private readonly Theme $theme,
        private readonly ViteManifest $manifest,
    ) {
        $this->urls = $site->urls;
        $config = $site->context->config;
        $this->timezone = $config->dateTimeZone();
        $this->locale = Locale::canonicalize($config->language) ?: 'en';
    }

    /**
     * Link to a page route, base path included.
     */
    public function url(string $route): string
    {
        return $this->urls->page($route);
    }

    /**
     * Link to a file in the output (`favicon.ico`, an attachment path).
     */
    public function asset(string $path): string
    {
        return $this->urls->asset($path);
    }

    /**
     * Absolute production URL of a route, null in a preview without `url`.
     */
    public function canonical(string $route): ?string
    {
        return $this->urls->canonical($route);
    }

    /**
     * The stylesheet links and module scripts of the theme (`@verseAssets`).
     */
    public function assetTags(): string
    {
        return $this->manifest->assetTags($this->urls);
    }

    /**
     * The contents of a file inside the theme directory, for inlining into a layout. Each file is read once per
     * build; later calls with the same path return the remembered contents.
     *
     * @phpstan-impure it reads the filesystem
     *
     * @throws ThemeException when the path leaves the theme or does not exist
     */
    public function inline(string $themeRelativePath): string
    {
        if (isset($this->inlineFilesCache[$themeRelativePath])) {
            return $this->inlineFilesCache[$themeRelativePath];
        }

        $root = realpath($this->theme->root);
        $file = $root === false ? false : realpath($root.'/'.$themeRelativePath);
        if ($root === false || $file === false || ! str_starts_with($file, $root.'/') || ! is_file($file)) {
            throw new ThemeException(
                'cannot inline "'.$themeRelativePath.'": it must be an existing file inside the theme folder.',
                'themes/'.$this->theme->name,
            );
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new ThemeException('cannot read "'.$themeRelativePath.'" to inline it.', 'themes/'.$this->theme->name);
        }

        return $this->inlineFilesCache[$themeRelativePath] = trim($contents);
    }

    /**
     * An inline `<svg class="icon …">` for one of the theme's icons.
     *
     * @param  string  $class  extra classes, e.g. `small`
     *
     * @throws ThemeException for an unknown icon name
     */
    public function icon(string $name, string $class = ''): string
    {
        $icon = self::ICONS[$name] ?? null;
        if ($icon === null) {
            throw new ThemeException('unknown icon "'.$name.'". Known icons: '.implode(', ', array_keys(self::ICONS)).'.', 'themes/'.$this->theme->name);
        }

        return '<svg class="icon'.($class === '' ? '' : ' '.htmlspecialchars($class, ENT_QUOTES)).'" viewBox="0 0 24 24" aria-hidden="true">'.$icon.'</svg>';
    }

    /**
     * A date in the site's language and timezone, by ICU pattern (`d MMM y`, `d MMMM y`).
     */
    public function formatDate(DateTimeInterface $date, string $pattern): string
    {
        $formatter = $this->formatters[$pattern] ??= new IntlDateFormatter(
            $this->locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $this->timezone,
            IntlDateFormatter::GREGORIAN,
            $pattern,
        );

        return (string) $formatter->format($date->getTimestamp());
    }

    /**
     * `YYYY-MM-DD` for `<time datetime>`, in the site timezone (the one that decides the month groups too).
     */
    public function isoDate(DateTimeInterface $date): string
    {
        return $this->inSiteZone($date)->format('Y-m-d');
    }

    public function year(DateTimeInterface $date): string
    {
        return $this->inSiteZone($date)->format('Y');
    }

    /**
     * Whether a menu item is the one that contains the page at `$route`.
     */
    public function isCurrent(MenuItem $item, string $route): bool
    {
        return $this->site->menu->current($route) === $item;
    }

    private function inSiteZone(DateTimeInterface $date): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone($this->timezone);
    }
}
