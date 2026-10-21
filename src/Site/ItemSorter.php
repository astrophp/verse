<?php

declare(strict_types=1);

namespace Astro\Verse\Site;

/**
 * Article ordering: newest first, ties by route or external URL.
 */
final class ItemSorter
{
    /**
     * @param  list<Page>  $items  articles (they always have a date)
     * @return list<Page>
     */
    public static function newestFirst(array $items): array
    {
        usort($items, static function (Page $a, Page $b): int {
            $result = ($b->meta->date?->getTimestamp() ?? 0) <=> ($a->meta->date?->getTimestamp() ?? 0);

            return $result !== 0 ? $result : strcmp($a->tieKey(), $b->tieKey());
        });

        return $items;
    }
}
