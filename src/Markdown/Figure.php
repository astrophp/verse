<?php

declare(strict_types=1);

namespace Astro\Verse\Markdown;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * A lone image with a title: the image (original node) plus its caption.
 */
final class Figure extends AbstractBlock
{
    public function __construct(public readonly string $caption)
    {
        parent::__construct();
    }
}
