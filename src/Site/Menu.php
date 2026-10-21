<?php

declare(strict_types=1);

namespace Astro\Verse\Site;

use Astro\Verse\Support\Unicode;

/**
 * The flat main menu.
 */
final readonly class Menu
{
    /**
     * @param  list<MenuItem>  $items  in display order
     */
    private function __construct(public array $items) {}

    /**
     * The fixed items come first in the order given, then the page items by `order` and label.
     *
     * @param  list<MenuItem>  $items  page items, any order
     * @param  list<MenuItem>  $leading  fixed items (Home, Articles, Tags)
     */
    public static function of(array $items, array $leading = []): self
    {
        usort($items, static function (MenuItem $a, MenuItem $b): int {
            if ($a->order !== null && $b->order !== null && $a->order !== $b->order) {
                return $a->order <=> $b->order;
            }
            if (($a->order === null) !== ($b->order === null)) {
                return $a->order === null ? 1 : -1;
            }

            return strcmp(Unicode::sortKey($a->label), Unicode::sortKey($b->label)) ?: strcmp($a->route, $b->route);
        });

        return new self([...$leading, ...$items]);
    }

    /**
     * The item that contains the current page: the longest menu route that prefixes it.
     * The home route `/` only matches itself.
     */
    public function current(string $route): ?MenuItem
    {
        $current = null;
        foreach ($this->items as $item) {
            $matches = $item->route === '/' ? $route === '/' : str_starts_with($route, $item->route);
            if ($matches && ($current === null || strlen($item->route) > strlen($current->route))) {
                $current = $item;
            }
        }

        return $current;
    }
}
