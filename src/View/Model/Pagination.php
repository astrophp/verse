<?php

declare(strict_types=1);

namespace Astro\Verse\View\Model;

final readonly class Pagination
{
    /**
     * @param  ?Link  $newer  the previous page of the listing, null on the first page
     * @param  ?Link  $older  the next page of the listing, null on the last page
     */
    public function __construct(
        public ?Link $newer,
        public ?Link $older,
        public string $status,
    ) {}
}
