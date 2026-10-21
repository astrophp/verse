<?php

declare(strict_types=1);

namespace Astro\Verse\Markdown;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;

/**
 * Pass 1 (links): rewrites the URL of every Link and Image node through the LinkResolver.
 */
final readonly class LinkRewriter
{
    public function __construct(
        private LinkResolver $links,
        private string $sourcePath,
    ) {}

    public function __invoke(DocumentParsedEvent $event): void
    {
        foreach ($event->getDocument()->iterator() as $node) {
            if ($node instanceof Link || $node instanceof Image) {
                $node->setUrl($this->links->resolve($this->sourcePath, $node->getUrl(), $node instanceof Image));
            }
        }
    }
}
