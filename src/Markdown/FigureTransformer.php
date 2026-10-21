<?php

declare(strict_types=1);

namespace Astro\Verse\Markdown;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Node\Block\Paragraph;

/**
 * Pass 3 (figures): a paragraph whose only child is an image with a title becomes a Figure. The original
 * Image node moves into the Figure (so CommonMark's own image rendering and URL safety still apply); its
 * title becomes the caption.
 */
final class FigureTransformer
{
    public function __invoke(DocumentParsedEvent $event): void
    {
        $paragraphs = [];
        foreach ($event->getDocument()->iterator() as $node) {
            if ($node instanceof Paragraph) {
                $paragraphs[] = $node;
            }
        }

        foreach ($paragraphs as $paragraph) {
            $image = $paragraph->firstChild();
            if (! $image instanceof Image || $image !== $paragraph->lastChild()) {
                continue;
            }
            $caption = $image->getTitle();
            if ($caption === null || trim($caption) === '') {
                continue;
            }

            $image->setTitle(null);
            $figure = new Figure($caption);
            $image->detach();
            $figure->appendChild($image);
            $paragraph->replaceWith($figure);
        }
    }
}
