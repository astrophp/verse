<?php

declare(strict_types=1);

namespace Astro\Verse\View\Model;

/**
 * Everything `layouts/base` needs around a page: document head, header and footer.
 */
final readonly class Layout
{
    /**
     * @param  string  $title  document title
     * @param  string  $pageTitle  the page's own title (`og:title`)
     * @param  ?string  $feedUrl  the feed's URL; null when the build writes no feed (previews, `feed: false`)
     * @param  ?string  $iconUrl  `theme.logo`; null selects the monogram icon
     * @param  string  $monogramIcon  data URI of the generated monogram icon
     * @param  ?string  $accentCss  trusted CSS built from validated hex colours, null without an accent setting
     * @param  list<Link>  $nav
     * @param  list<Link>  $social
     */
    public function __construct(
        public string $language,
        public string $title,
        public string $pageTitle,
        public ?string $description,
        public ?string $canonical,
        public ?string $feedUrl,
        public string $ogType,
        public ?string $ogImage,
        public bool $noindex,
        public ?string $iconUrl,
        public string $monogramIcon,
        public ?string $accentCss,
        public string $homeUrl,
        public string $brandName,
        public ?string $brandRole,
        public string $brandLabel,
        public array $nav,
        public string $copyright,
        public array $social,
    ) {}
}
