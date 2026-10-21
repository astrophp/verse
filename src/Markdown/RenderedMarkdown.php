<?php

declare(strict_types=1);

namespace Astro\Verse\Markdown;

final readonly class RenderedMarkdown
{
    private const int WORDS_PER_MINUTE = 200;

    /**
     * @param  string  $html  trusted markup produced by the renderer; insert it once, unescaped, never through Blade
     * @param  list<Heading>  $headings  h2 and h3 in document order
     */
    public function __construct(
        public string $html,
        public array $headings,
        public int $wordCount,
    ) {}

    public function readingMinutes(): int
    {
        return max(1, (int) ceil($this->wordCount / self::WORDS_PER_MINUTE));
    }
}
