<?php

declare(strict_types=1);

namespace Astro\Verse\Markdown;

use InvalidArgumentException;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

final class CalloutRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        if (! $node instanceof Callout) {
            throw new InvalidArgumentException('Unexpected node '.$node::class);
        }

        return new HtmlElement(
            'div',
            ['class' => 'callout callout-'.$node->kind],
            $childRenderer->renderNodes($node->children()),
        );
    }
}
