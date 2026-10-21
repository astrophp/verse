<?php

declare(strict_types=1);

namespace Astro\Verse\View\Model;

final readonly class TimelineEntry
{
    /**
     * @param  bool  $external  the entry links to another site
     * @param  ?string  $stateLabel  "Draft" or "Scheduled" (drafts preview only)
     * @param  list<TagRef>  $tags
     */
    public function __construct(
        public string $title,
        public ?string $description,
        public string $url,
        public bool $external,
        public string $isoDate,
        public string $dateLabel,
        public ?string $stateLabel,
        public array $tags,
    ) {}
}
