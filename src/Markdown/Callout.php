<?php

declare(strict_types=1);

namespace Astro\Verse\Markdown;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * A `> [!NOTE]` style blockquote; the first child is the title paragraph.
 */
final class Callout extends AbstractBlock
{
    public function __construct(public readonly string $kind)
    {
        parent::__construct();
    }
}
