<?php

declare(strict_types=1);

namespace Astro\Verse\Markdown;

use Astro\Verse\Support\Slug;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading as HeadingNode;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;

/**
 * Pass 4 (heading IDs and outline): gives every heading a unique id in document order and collects the
 * h2/h3 outline. The used-ID set starts with the layout's own ids; all state lives in this instance, which
 * is created for one document.
 */
final class HeadingIndexer
{
    /** @var list<string> ids the page layout uses */
    public const array RESERVED_IDS = ['main-content', 'site-header', 'theme-button', 'theme-options', 'reading-progress'];

    /** @var array<string, true> */
    private array $used;

    /** @var list<Heading> */
    private array $outline = [];

    public function __construct()
    {
        $this->used = array_fill_keys(self::RESERVED_IDS, true);
    }

    /**
     * @return list<Heading>
     */
    public function outline(): array
    {
        return $this->outline;
    }

    public function __invoke(DocumentParsedEvent $event): void
    {
        foreach ($event->getDocument()->iterator() as $node) {
            if (! $node instanceof HeadingNode) {
                continue;
            }

            $text = trim(self::textOf($node));
            $id = $this->uniqueId(Slug::make($text));
            $node->data->set('attributes/id', $id);

            if ($node->getLevel() === 2 || $node->getLevel() === 3) {
                $this->outline[] = new Heading($node->getLevel(), $text, $id);
            }
        }
    }

    private function uniqueId(string $slug): string
    {
        $id = $slug;
        for ($suffix = 2; isset($this->used[$id]); $suffix++) {
            $id = $slug.'-'.$suffix;
        }
        $this->used[$id] = true;

        return $id;
    }

    /**
     * The literal text of Text and Code descendants (link text and image alt text included), without markup.
     */
    private static function textOf(Node $heading): string
    {
        $text = '';
        foreach ($heading->iterator() as $node) {
            if ($node instanceof Text || $node instanceof Code) {
                $text .= $node->getLiteral();
            }
        }

        return $text;
    }
}
