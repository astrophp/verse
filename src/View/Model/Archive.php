<?php

declare(strict_types=1);

namespace Astro\Verse\View\Model;

/**
 * The optional toolbar, optional count, month-grouped list (or empty state) and pagination of a listing page or the home page's short list.
 */
final readonly class Archive
{
    /**
     * @param  ?string  $sectionLabel  null on tag archives, which show no toolbar
     * @param  ?string  $countLabel  null on the home page, which shows no count
     * @param  list<TimelineGroup>  $groups  empty means the empty state
     * @param  ?Pagination  $pagination  null when everything fits on one page
     */
    public function __construct(
        public ?string $sectionLabel,
        public ?string $countLabel,
        public array $groups,
        public ?Pagination $pagination,
    ) {}
}
