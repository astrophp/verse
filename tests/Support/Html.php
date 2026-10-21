<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * A small typed XPath view of an HTML string, for assertions on rendered pages.
 */
final class Html
{
    private function __construct(private readonly DOMXPath $xpath) {}

    public static function parse(string $html): self
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new self(new DOMXPath($document));
    }

    /**
     * @return list<DOMNode>
     */
    public function nodes(string $query): array
    {
        $result = $this->xpath->query($query);
        if ($result === false) {
            throw new \InvalidArgumentException('Invalid XPath query: '.$query);
        }

        $nodes = [];
        foreach ($result as $node) {
            if ($node instanceof DOMNode) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * The decoded SVG of the page's `data:` icon, '' when the icon is a file.
     */
    public static function inlineIcon(string $html): string
    {
        return preg_match('~<link rel="icon" href="data:image/svg\+xml,([^"]+)">~', $html, $match) === 1
            ? rawurldecode($match[1])
            : '';
    }

    public function count(string $query): int
    {
        return count($this->nodes($query));
    }

    /**
     * Trimmed text of the first match, '' without one.
     */
    public function text(string $query): string
    {
        $node = $this->nodes($query)[0] ?? null;

        return $node === null ? '' : trim($node->textContent);
    }

    /**
     * @return list<string>
     */
    public function texts(string $query): array
    {
        return array_map(static fn (DOMNode $node): string => trim($node->textContent), $this->nodes($query));
    }

    /**
     * An attribute of the first match, null when there is no match or no such attribute.
     */
    public function attr(string $query, string $name): ?string
    {
        $node = $this->nodes($query)[0] ?? null;

        return $node instanceof DOMElement && $node->hasAttribute($name) ? $node->getAttribute($name) : null;
    }

    /**
     * @return list<string>
     */
    public function attrs(string $query, string $name): array
    {
        $values = [];
        foreach ($this->nodes($query) as $node) {
            if ($node instanceof DOMElement && $node->hasAttribute($name)) {
                $values[] = $node->getAttribute($name);
            }
        }

        return $values;
    }
}
