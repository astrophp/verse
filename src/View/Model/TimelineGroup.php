<?php

declare(strict_types=1);

namespace Astro\Verse\View\Model;

/**
 * A contiguous run of timeline entries of the same month, in display order.
 */
final readonly class TimelineGroup
{
    /**
     * @param  string  $label  the short month label shown beside the run, e.g. "2026 Oct"
     * @param  string  $fullLabel  the spoken month, e.g. "October 2026"
     * @param  list<TimelineEntry>  $entries
     */
    public function __construct(
        public string $label,
        public string $fullLabel,
        public array $entries,
    ) {}
}
