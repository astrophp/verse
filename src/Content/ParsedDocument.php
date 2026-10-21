<?php

declare(strict_types=1);

namespace Astro\Verse\Content;

final readonly class ParsedDocument
{
    public function __construct(
        public FrontMatter $frontMatter,
        public string $body,
    ) {}
}
