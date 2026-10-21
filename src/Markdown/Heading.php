<?php

declare(strict_types=1);

namespace Astro\Verse\Markdown;

/**
 * An outline entry: the id is the one on the rendered heading and in the table of contents.
 */
final readonly class Heading
{
    public function __construct(
        public int $level,
        public string $text,
        public string $id,
    ) {}
}
