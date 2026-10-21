<?php

declare(strict_types=1);

namespace Astro\Verse\View\Model;

final readonly class ArticleView
{
    /**
     * @param  ?string  $isoDate  `YYYY-MM-DD` for the `<time>` element
     * @param  ?string  $stateLabel  "Draft" or "Scheduled" (drafts preview only)
     * @param  list<TagRef>  $tags
     * @param  list<TocEntry>  $toc  empty when the article shows no table of contents
     * @param  string  $bodyHtml  trusted markup from the Markdown renderer
     * @param  Link  $end  "Read the original" (external) or "Back to {articles title}"
     */
    public function __construct(
        public string $title,
        public ?string $description,
        public Link $back,
        public ?string $isoDate,
        public ?string $dateLabel,
        public ?string $stateLabel,
        public int $readingMinutes,
        public array $tags,
        public array $toc,
        public string $bodyHtml,
        public Link $end,
        public ?Link $related,
    ) {}
}
