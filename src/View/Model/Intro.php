<?php

declare(strict_types=1);

namespace Astro\Verse\View\Model;

/**
 * A page introduction: heading, optional description and optional rendered Markdown that follows it.
 */
final readonly class Intro
{
    /**
     * @param  ?string  $bodyHtml  trusted markup from the Markdown renderer
     * @param  ?string  $stateLabel  "Draft" or "Scheduled" (drafts preview only)
     * @param  bool  $tag  the title is a tag label, shown after a decorative hash
     */
    public function __construct(
        public string $title,
        public ?string $description = null,
        public ?string $bodyHtml = null,
        public ?string $stateLabel = null,
        public bool $tag = false,
    ) {}

    /**
     * Whether anything besides the heading would be shown.
     */
    public function hasContent(): bool
    {
        return $this->description !== null || $this->bodyHtml !== null || $this->stateLabel !== null;
    }
}
