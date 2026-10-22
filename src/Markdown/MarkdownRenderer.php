<?php

declare(strict_types=1);

namespace Astro\Verse\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Event\DocumentPreParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Table\TableRenderer;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Normalizer\UniqueSlugNormalizerInterface;
use League\CommonMark\Renderer\HtmlDecorator;
use Tempest\Highlight\Highlighter;

/**
 * Markdown to trusted HTML under the Verse contract. Content never executes: raw HTML is
 * escaped, unsafe links are neutralised, code is escaped or highlighted, and the result is meant to be
 * printed unescaped exactly once and never compiled by Blade.
 *
 * The CommonMark environment is built once per renderer. The per-document state (link resolver and
 * source path, used heading ids, outline) lives in a LinkRewriter and a HeadingIndexer created for each
 * render. Renders must not be nested, because the CommonMark parser is not re-entrant.
 */
final class MarkdownRenderer
{
    // DocumentParsedEvent priorities (higher runs first). The footnote extension uses 40 down to 10;
    // the Verse passes follow it, in this order: links, callouts, figures, heading ids.
    private const int LINKS = -10;

    private const int CALLOUTS = -20;

    private const int FIGURES = -30;

    private const int HEADINGS = -40;

    private readonly MarkdownConverter $converter;

    // The per-document passes of the render in progress; set by render(), cleared when it ends.
    private ?LinkRewriter $links = null;

    private ?HeadingIndexer $headings = null;

    public function __construct(Highlighter $highlighter = new Highlighter)
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 100,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $environment->addExtension(new FootnoteExtension);

        $code = new CodeBlockRenderer($highlighter);
        $environment->addRenderer(FencedCode::class, $code, 10);
        $environment->addRenderer(IndentedCode::class, $code, 10);
        $environment->addRenderer(Table::class, new HtmlDecorator(
            new TableRenderer,
            'div',
            ['class' => 'table-scroll', 'tabindex' => '0', 'role' => 'region', 'aria-label' => 'Table'],
        ), 10);
        $environment->addRenderer(Figure::class, new FigureRenderer);
        $environment->addRenderer(Callout::class, new CalloutRenderer);

        // A render that throws never reaches CommonMark's own end-of-document reset of the slug
        // history (used for inline footnote ids), so clear it before every document as well.
        $environment->addEventListener(DocumentPreParsedEvent::class, static function () use ($environment): void {
            $normalizer = $environment->getSlugNormalizer();
            if ($normalizer instanceof UniqueSlugNormalizerInterface) {
                $normalizer->clearHistory();
            }
        });

        $environment->addEventListener(DocumentParsedEvent::class, fn (DocumentParsedEvent $event) => $this->links?->__invoke($event), self::LINKS);
        $environment->addEventListener(DocumentParsedEvent::class, new CalloutTransformer, self::CALLOUTS);
        $environment->addEventListener(DocumentParsedEvent::class, new FigureTransformer, self::FIGURES);
        $environment->addEventListener(DocumentParsedEvent::class, fn (DocumentParsedEvent $event) => $this->headings?->__invoke($event), self::HEADINGS);

        $this->converter = new MarkdownConverter($environment);
    }

    /**
     * @param  string  $sourcePath  site-relative file the Markdown comes from; relative links resolve against its folder
     */
    public function render(string $markdown, string $sourcePath, LinkResolver $links): RenderedMarkdown
    {
        $headings = new HeadingIndexer;
        $this->links = new LinkRewriter($links, $sourcePath);
        $this->headings = $headings;

        try {
            $html = $this->converter->convert($markdown)->getContent();
        } finally {
            $this->links = null;
            $this->headings = null;
        }

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
