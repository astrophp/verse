<?php

declare(strict_types=1);

namespace Astro\Verse\View;

use Astro\Verse\Build\Feed;
use Astro\Verse\Site\SiteModel;
use Astro\Verse\View\Model\Layout;
use Astro\Verse\View\Model\Link;

/**
 * Builds the document head, header and footer data of a page.
 */
final readonly class LayoutBuilder
{
    public function __construct(private SiteModel $site, private ThemeContext $theme) {}

    /**
     * @param  string  $pageTitle  the page's own title; the home page's document title is the site title alone
     * @param  ?string  $description  the page description; the home page falls back to the site description
     * @param  string  $menuRoute  the route whose menu item is marked current ('' marks none)
     */
    public function build(
        string $pageTitle,
        bool $isHome,
        ?string $description,
        ?string $canonical,
        string $menuRoute,
        string $ogType = 'website',
        ?string $ogImage = null,
        bool $noindex = false,
    ): Layout {
        $config = $this->site->context->config;
        $settings = $config->theme;

        $description = $description === null || trim($description) === '' ? null : $description;
        if ($description === null && $isHome) {
            $description = $config->description === null || trim($config->description) === '' ? null : $config->description;
        }

        $nav = [];
        foreach ($this->site->menu->items as $item) {
            $nav[] = new Link($item->label, $this->theme->url($item->route), $this->theme->isCurrent($item, $menuRoute));
        }
        $social = [];
        foreach ($config->socialLinks as $link) {
            $social[] = new Link($link->label, $link->url, external: true);
        }

        $tagline = $config->tagline;
        $name = $config->author->name ?? $config->title;

        return new Layout(
            language: $config->language,
            title: $isHome ? $config->title : $pageTitle.' — '.$config->title,
            pageTitle: $pageTitle,
            description: $description,
            canonical: $canonical,
            feedUrl: $this->site->context->publishesFeed() ? $this->theme->asset(Feed::PATH) : null,
            ogType: $ogType,
            ogImage: $ogImage,
            noindex: $noindex,
            iconUrl: $this->iconUrl($settings->logo),
            monogramIcon: self::monogram($config->title),
            accentCss: self::accentCss($settings->accentLight, $settings->accentDark),
            homeUrl: $this->theme->url('/'),
            brandName: $config->title,
            brandRole: $tagline,
            brandLabel: $config->title.($tagline === null ? '' : ', '.$tagline).' — home',
            nav: $nav,
            copyright: '© '.$this->theme->year($this->site->context->now).' '.$name,
            social: $social,
        );
    }

    private function iconUrl(?string $logo): ?string
    {
        if ($logo === null) {
            return null;
        }

        return str_starts_with($logo, '/') ? $this->theme->asset(ltrim($logo, '/')) : $logo;
    }

    /**
     * The default theme's inline monogram: the first letter of the brand name, lowercased and XML-escaped.
     */
    private static function monogram(string $brand): string
    {
        $letter = htmlspecialchars(mb_strtolower(mb_substr(trim($brand), 0, 1)), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' rx='14' fill='#202124'/>"
            ."<text x='15' y='45' fill='#fcfcfc' font-family='Arial,sans-serif' font-size='38'>".$letter.'</text></svg>';

        return 'data:image/svg+xml,'.rawurlencode($svg);
    }

    /**
     * Unlayered rules that beat the layered theme tokens, each mode only in its own context: the light value
     * where the page is light (`data-theme=light`, or no attribute and no dark system preference) and the dark
     * value where it is dark. A mode left at null keeps the theme's own colour. Colours are validated hex values.
     */
    private static function accentCss(?string $light, ?string $dark): ?string
    {
        $css = '';
        if ($light !== null) {
            $css .= ':root[data-theme=light]{--accent:'.$light.'}'
                .'@media not (prefers-color-scheme:dark){:root:not([data-theme=dark]){--accent:'.$light.'}}';
        }
        if ($dark !== null) {
            $css .= ':root[data-theme=dark]{--accent:'.$dark.'}'
                .'@media (prefers-color-scheme:dark){:root:not([data-theme=light]){--accent:'.$dark.'}}';
        }

        return $css === '' ? null : $css;
    }
}
