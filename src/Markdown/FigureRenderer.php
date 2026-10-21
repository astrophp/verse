<?php

declare(strict_types=1);

namespace Astro\Verse\Markdown;

use InvalidArgumentException;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Util\Xml;

final class FigureRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        if (! $node instanceof Figure) {
            throw new InvalidArgumentException('Unexpected node '.$node::class);
        }

        return new HtmlElement('figure', [], $childRenderer->renderNodes($node->children())
            .new HtmlElement('figcaption', [], Xml::escape($node->caption)));
    }
}
