<?php

declare(strict_types=1);

namespace Astro\Verse\Site;

final readonly class ListingPage
{
    /**
     * @param  list<Page>  $items  the items on this page
     */
    public function __construct(
        public int $number,
        public string $route,
        public array $items,
        public int $pageCount,
        public int $itemCount,
        public ?string $previousRoute,
        public ?string $nextRoute,
    ) {}
}
