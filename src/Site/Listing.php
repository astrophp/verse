<?php

declare(strict_types=1);

namespace Astro\Verse\Site;

use Astro\Verse\Routing\RouteResolver;

/**
 * A sorted list of items split into pages. An empty list is still one page (an empty state), never phantom pages.
 */
final readonly class Listing
{
    /**
     * @param  list<Page>  $items  all items in display order
     * @param  list<ListingPage>  $pages  never empty
     */
    private function __construct(
        public string $route,
        public array $items,
        public array $pages,
    ) {}

    /**
     * @param  list<Page>  $items  already sorted
     */
    public static function paginate(string $route, array $items, int $perPage): self
    {
        $itemCount = count($items);
        $pageCount = max(1, intdiv($itemCount + $perPage - 1, $perPage));

        $pages = [];
        for ($number = 1; $number <= $pageCount; $number++) {
            $pages[] = new ListingPage(
                number: $number,
                route: RouteResolver::pageRoute($route, $number),
                items: array_slice($items, ($number - 1) * $perPage, $perPage),
                pageCount: $pageCount,
                itemCount: $itemCount,
                previousRoute: $number > 1 ? RouteResolver::pageRoute($route, $number - 1) : null,
                nextRoute: $number < $pageCount ? RouteResolver::pageRoute($route, $number + 1) : null,
            );
        }

        return new self($route, $items, $pages);
    }

    public function itemCount(): int
    {
        return count($this->items);
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }
}
