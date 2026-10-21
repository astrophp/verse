<?php

declare(strict_types=1);

namespace Astro\Verse\Markdown;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;

/**
 * Pass 2 (callouts): a blockquote whose first line is `[!NOTE]`, `[!TIP]`, `[!IMPORTANT]`, `[!WARNING]` or
 * `[!CAUTION]` (any letter case) becomes a Callout. The inline nodes after the marker up to the first line break are the title
 * (default: the kind's name); the marker line is removed from the first paragraph, which is dropped when empty.
 */
final class CalloutTransformer
{
    private const array TITLES = [
        'NOTE' => 'Note',
        'TIP' => 'Tip',
        'IMPORTANT' => 'Important',
        'WARNING' => 'Warning',
        'CAUTION' => 'Caution',
    ];

    public function __invoke(DocumentParsedEvent $event): void
    {
        $blockQuotes = [];
        foreach ($event->getDocument()->iterator() as $node) {
            if ($node instanceof BlockQuote) {
                $blockQuotes[] = $node;
            }
        }

        foreach ($blockQuotes as $blockQuote) {
            $this->transform($blockQuote);
        }
    }

    private function transform(BlockQuote $blockQuote): void
    {
        $paragraph = $blockQuote->firstChild();
        $marker = $paragraph?->firstChild();
        if (! $paragraph instanceof Paragraph || ! $marker instanceof Text) {
            return;
        }
        if (preg_match('/^\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\](?=[ \t]|$)/i', $marker->getLiteral(), $match) !== 1) {
            return;
        }

        $kind = strtoupper($match[1]);
        $rest = ltrim(substr($marker->getLiteral(), strlen($match[0])), " \t");

        // Title: what is left of the marker's text node, then every inline up to the first line break.
        $titleNodes = [];
        if ($rest !== '') {
            $titleNodes[] = new Text($rest);
        }
        $next = $marker->next();
        $marker->detach();
        while ($next !== null && ! $next instanceof Newline) {
            $following = $next->next();
            $next->detach();
            $titleNodes[] = $next;
            $next = $following;
        }
        $next?->detach(); // the line break after the title line

        if ($titleNodes === []) {
            $titleNodes[] = new Text(self::TITLES[$kind]);
        }
        $title = new Paragraph;
        $title->data->set('attributes/class', 'callout-title');
        foreach ($titleNodes as $titleNode) {
            $title->appendChild($titleNode);
        }

        $callout = new Callout(strtolower($kind));
        $callout->appendChild($title);
        if (! $paragraph->hasChildren()) {
            $paragraph->detach();
        }
        foreach ($this->childrenOf($blockQuote) as $child) {
            $child->detach();
            $callout->appendChild($child);
        }
        $blockQuote->replaceWith($callout);
    }

    /**
     * @return list<Node>
     */
    private function childrenOf(Node $node): array
    {
        $children = [];
        foreach ($node->children() as $child) {
            $children[] = $child;
        }

        return $children;
    }
}
