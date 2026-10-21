<?php

declare(strict_types=1);

namespace Astro\Verse\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Table\TableRenderer;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Renderer\HtmlDecorator;
use Tempest\Highlight\Highlighter;

/**
 * Markdown to trusted HTML under the Verse contract. Content never executes: raw HTML is
 * escaped, unsafe links are neutralised, code is escaped or highlighted, and the result is meant to be
 * printed unescaped exactly once and never compiled by Blade.
 *
 * Every render builds its own environment, so the per-document state (link resolver, used heading ids,
 * outline) lives in listeners that exist for one invocation only.
 */
final class MarkdownRenderer
{
    // DocumentParsedEvent priorities (higher runs first). The footnote extension uses 40 down to 10;
    // the Verse passes follow it, in this order: links, callouts, figures, heading ids.
    private const int LINKS = -10;

    private const int CALLOUTS = -20;

    private const int FIGURES = -30;

    private const int HEADINGS = -40;

    public function __construct(private readonly Highlighter $highlighter = new Highlighter) {}

    /**
     * @param  string  $sourcePath  site-relative file the Markdown comes from; relative links resolve against its folder
     */
    public function render(string $markdown, string $sourcePath, LinkResolver $links): RenderedMarkdown
    {
        $headings = new HeadingIndexer;

        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 100,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $environment->addExtension(new FootnoteExtension);

        $code = new CodeBlockRenderer($this->highlighter);
        $environment->addRenderer(FencedCode::class, $code, 10);
        $environment->addRenderer(IndentedCode::class, $code, 10);
        $environment->addRenderer(Table::class, new HtmlDecorator(
            new TableRenderer,
            'div',
            ['class' => 'table-scroll', 'tabindex' => '0', 'role' => 'region', 'aria-label' => 'Table'],
        ), 10);
        $environment->addRenderer(Figure::class, new FigureRenderer);
        $environment->addRenderer(Callout::class, new CalloutRenderer);

        $environment->addEventListener(DocumentParsedEvent::class, new LinkRewriter($links, $sourcePath), self::LINKS);
        $environment->addEventListener(DocumentParsedEvent::class, new CalloutTransformer, self::CALLOUTS);
        $environment->addEventListener(DocumentParsedEvent::class, new FigureTransformer, self::FIGURES);
        $environment->addEventListener(DocumentParsedEvent::class, $headings, self::HEADINGS);

        $html = (new MarkdownConverter($environment))->convert($markdown)->getContent();

        return new RenderedMarkdown($html, $headings->outline(), self::countWords($html));
    }

    /**
     * Words of the rendered text: tags stripped (block-level tags separate words, inline tags such as
     * `un<strong>break</strong>able` do not), entities decoded, tokens without a letter or digit (for
     * example the footnote back-arrow) not counted.
     */
    private static function countWords(string $html): int
    {
        $spaced = preg_replace('~</?(?:p|li|ul|ol|h[1-6]|td|th|tr|thead|tbody|table|pre|blockquote|div|figure|figcaption|br|hr|sup)\b[^>]*>~i', ' ', $html) ?? '';
        $text = html_entity_decode(preg_replace('/<[^>]*>/', '', $spaced) ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count(array_filter($tokens, static fn (string $token): bool => preg_match('/[\p{L}\p{N}]/u', $token) === 1));
    }
}
