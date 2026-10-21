<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Markdown;

use Astro\Verse\Error\ContentException;
use Astro\Verse\Markdown\Heading;
use Astro\Verse\Markdown\LinkResolver;
use Astro\Verse\Markdown\MarkdownRenderer;
use Astro\Verse\Markdown\RenderedMarkdown;
use Astro\Verse\Tests\Support\RecordingLinkResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    private function render(string $markdown, ?LinkResolver $links = null): RenderedMarkdown
    {
        return (new MarkdownRenderer)->render($markdown, 'content/x.md', $links ?? new RecordingLinkResolver);
    }

    /**
     * @return list<string>
     */
    private static function ids(RenderedMarkdown $rendered): array
    {
        return array_map(static fn (Heading $heading): string => $heading->id, $rendered->headings);
    }

    /**
     * The visible text of a rendered code block: tags removed, entities decoded.
     */
    private static function codeText(string $html): string
    {
        preg_match('~<code[^>]*>(.*?)</code>~s', $html, $match);
        $content = $match[1] ?? '';

        // Inside <code> only highlight spans may exist: no other tags, no other attributes.
        preg_match_all('~<[^>]*>~', $content, $tags);
        foreach ($tags[0] as $tag) {
            self::assertMatchesRegularExpression('~^(?:</span>|<span class="hl-[a-z-]+(?: hl-[a-z-]+)*">)$~', $tag);
        }

        return html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5);
    }

    // --- code blocks -------------------------------------------------------------------------------

    #[Test]
    public function code_blocks_use_the_theme_markup(): void
    {
        $html = $this->render("```php\necho 1;\n```\n")->html;

        $this->assertStringStartsWith('<div class="code-block"><div class="code-header"><span>PHP</span></div><pre tabindex="0" aria-label="Code example"><code class="language-php">', $html);
        $this->assertStringContainsString('hl-keyword', $html);
        $this->assertStringEndsWith("</code></pre></div>\n", $html);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function literalCode(): iterable
    {
        $source = "<?php echo \"{{ \$x }}\"; // <x-foo/> @if(\$a) @endif\n{{ \$x }} {!! \$y !!} @if(true) <x-foo/>\n";
        foreach (['php', 'blade', 'html', 'js', 'text', 'nope', ''] as $language) {
            yield 'language '.($language === '' ? '(none)' : $language) => [$language, $source];
        }
    }

    #[Test]
    #[DataProvider('literalCode')]
    public function code_is_escaped_and_literal_whatever_it_contains(string $language, string $source): void
    {
        $html = $this->render("```{$language}\n{$source}```\n")->html;

        $this->assertStringNotContainsString('<x-foo', $html);
        $this->assertStringNotContainsString('<?php', $html);
        $this->assertSame(rtrim($source, "\n"), self::codeText($html));
    }

    #[Test]
    public function an_indented_block_is_a_plain_code_block(): void
    {
        $html = $this->render("Intro\n\n    <?php echo {{ \$x }};\n    @if\n")->html;

        $this->assertStringContainsString('<div class="code-header"><span>Code</span></div><pre tabindex="0" aria-label="Code example"><code>&lt;?php echo {{ $x }};'."\n".'@if</code></pre>', $html);
    }

    #[Test]
    public function an_unknown_language_is_plain_text_labelled_code_without_a_class(): void
    {
        $html = $this->render("```cobol\nMOVE <A> & B\n```\n")->html;

        $this->assertStringContainsString('<span>Code</span>', $html);
        $this->assertStringContainsString('<code>MOVE &lt;A&gt; &amp; B</code>', $html);
        $this->assertStringNotContainsString('language-', $html);
        $this->assertStringNotContainsString('hl-', $html);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function displayNames(): iterable
    {
        foreach ([
            'php' => 'PHP', 'blade' => 'Blade', 'js' => 'JavaScript', 'javascript' => 'JavaScript', 'ts' => 'TypeScript',
            'json' => 'JSON', 'yaml' => 'YAML', 'yml' => 'YAML', 'html' => 'HTML', 'css' => 'CSS', 'sql' => 'SQL',
            'bash' => 'Shell', 'sh' => 'Shell', 'shell' => 'Shell', 'zsh' => 'Shell', 'diff' => 'Diff', 'xml' => 'XML',
            'markdown' => 'Markdown', 'md' => 'Markdown', 'text' => 'Text', 'txt' => 'Text',
        ] as $word => $label) {
            yield $word => [$word, $label];
        }
    }

    #[Test]
    #[DataProvider('displayNames')]
    public function known_languages_get_their_display_name_and_class(string $word, string $label): void
    {
        $html = $this->render("```{$word}\nsome code\n```\n")->html;

        $this->assertStringContainsString('<span>'.$label.'</span>', $html);
        $this->assertStringContainsString('<code class="language-'.$word.'">', $html);
    }

    #[Test]
    public function only_the_first_info_word_matters_and_is_lowercased(): void
    {
        $this->assertStringContainsString('<code class="language-php">', $this->render("```PHP title=\"x\"\n1\n```\n")->html);

        $hostile = $this->render("```\"><script>alert(1)</script>\n1\n```\n")->html;
        $this->assertStringNotContainsString('<script', $hostile);
        $this->assertStringNotContainsString('language-', $hostile);
        $this->assertStringContainsString('<span>Code</span>', $hostile);
    }

    #[Test]
    public function the_highlighters_own_markers_and_token_keys_stay_literal(): void
    {
        $source = "<?php\n\$a = '{+ added +}'; // {- removed -} {* strong *} {~ blur ~} {_ em _}\n{:hl-x:text:} and ❶❿ end\n";
        $html = $this->render("```php\n{$source}```\n")->html;

        $this->assertSame(rtrim($source, "\n"), self::codeText($html));
        $this->assertStringNotContainsString('display: none', $html);
        $this->assertStringNotContainsString('hl-addition', $html);
        $this->assertStringNotContainsString('class="hl-x"', $html);
        $this->assertStringContainsString('<code class="language-php">', $html);
        $this->assertStringContainsString('<span>PHP</span>', $html);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function embeddedMarkers(): iterable
    {
        $markers = '{+ a +} {- b -} {* c *} {~ d ~} {_ e _} {:hl-x:f:}';
        yield 'html with script and style' => ['html', "<script>var a = \"{$markers}\";</script>\n<style>a { } /* {$markers} */</style>"];
        yield 'blade with php' => ['blade', "@php echo \"{$markers}\"; @endphp {{ \$a }} {$markers}"];
        yield 'vue' => ['vue', "<script>let a = \"{$markers}\"</script>\n<template>{$markers}</template>"];
        yield 'markdown with fence' => ['markdown', "~~~php\n\$a = \"{$markers}\";\n~~~"];
        yield 'twig' => ['twig', "{% if a %}{$markers}{% endif %} {{ b }}"];
        yield 'javascript' => ['js', "/** {$markers} */ const a = \"{$markers}\""];
        yield 'diff' => ['diff', "- old {$markers}\n+ new {$markers}"];
        yield 'text' => ['text', $markers];
    }

    #[Test]
    #[DataProvider('embeddedMarkers')]
    public function markers_stay_literal_in_every_language_and_embedded_code(string $language, string $source): void
    {
        $html = $this->render("```{$language}\n{$source}\n```\n")->html;

        $this->assertSame($source, self::codeText($html));
        $this->assertStringNotContainsString('display: none', $html);
        $this->assertStringNotContainsString('class="hl-strong"', $html);
        $this->assertStringNotContainsString('class="hl-blur"', $html);
        $this->assertStringNotContainsString('class="hl-em"', $html);
        $this->assertStringNotContainsString('class="hl-x"', $html);
    }

    #[Test]
    public function code_with_the_highlighters_internal_characters_is_not_highlighted(): void
    {
        $html = $this->render("```php\n\$a = '❶❷❸❹❿ \u{E000} <b>';\n```\n")->html;

        $this->assertSame("\$a = '❶❷❸❹❿ \u{E000} <b>';", self::codeText($html));
        $this->assertStringNotContainsString('hl-', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringContainsString('<code class="language-php">', $html);
    }

    #[Test]
    public function tempest_languages_without_a_name_in_the_plan_are_highlighted_with_their_label(): void
    {
        $html = $this->render("```python\ndef f(x):\n    return x\n```\n")->html;

        $this->assertStringContainsString('<span>Python</span>', $html);
        $this->assertStringContainsString('<code class="language-python">', $html);
        $this->assertStringContainsString('hl-keyword', $html);
        $this->assertSame("def f(x):\n    return x", self::codeText($html));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function extraLanguages(): iterable
    {
        foreach ([
            'py' => 'Python', 'ruby' => 'Ruby', 'rb' => 'Ruby', 'swift' => 'Swift', 'scss' => 'SCSS', 'dockerfile' => 'Dockerfile',
            'docker' => 'Dockerfile', 'graphql' => 'GraphQL', 'gql' => 'GraphQL', 'ini' => 'INI', 'nginx' => 'Nginx',
            'apache' => 'Apache', 'twig' => 'Twig', 'vue' => 'Vue', 'jsx' => 'JSX', 'tsx' => 'TSX', 'svelte' => 'Svelte',
            'terraform' => 'Terraform', 'tf' => 'Terraform', 'dotenv' => '.env', 'env' => '.env', 'http' => 'HTTP', 'gdscript' => 'GDScript',
        ] as $word => $label) {
            yield $word => [$word, $label];
        }
    }

    #[Test]
    #[DataProvider('extraLanguages')]
    public function every_supported_tempest_language_has_a_label(string $word, string $label): void
    {
        $html = $this->render("```{$word}\nsome = code\n```\n")->html;

        $this->assertStringContainsString('<span>'.$label.'</span>', $html);
        $this->assertStringContainsString('<code class="language-'.$word.'">', $html);
        $this->assertSame('some = code', self::codeText($html));
    }

    #[Test]
    public function highlighting_adds_only_class_spans_and_keeps_the_text(): void
    {
        $html = $this->render("```diff\n- old <b>\n+ new\n```\n")->html;

        $this->assertStringContainsString('hl-', $html);
        $this->assertSame("- old <b>\n+ new", self::codeText($html));
    }

    // --- raw HTML, unsafe links ------------------------------------------------------------------

    #[Test]
    public function raw_html_is_escaped(): void
    {
        $html = $this->render("<script>alert(1)</script>\n\nText <img src=x onerror=alert(1)> and <b>bold</b>.\n")->html;

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    #[Test]
    public function unsafe_link_and_image_urls_are_neutralised(): void
    {
        $html = $this->render("[click](javascript:alert(1)) ![pic](javascript:alert(2)) [data](data:text/html;base64,AAAA)\n")->html;

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:text', $html);
        $this->assertStringContainsString('<img src="" alt="pic" />', $html);
    }

    // --- tables, footnotes -----------------------------------------------------------------------

    #[Test]
    public function tables_are_wrapped_in_a_scroll_region(): void
    {
        $html = $this->render("| a | b |\n|---|---|\n| 1 | 2 |\n")->html;

        $this->assertStringStartsWith('<div class="table-scroll" tabindex="0" role="region" aria-label="Table"><table>', $html);
        $this->assertStringEndsWith("</table></div>\n", $html);
        $this->assertSame(1, substr_count($html, '<table>'));
    }

    #[Test]
    public function footnotes_use_the_extension_markup(): void
    {
        $html = $this->render("Claim[^1].\n\n[^1]: The source.\n")->html;

        $this->assertStringContainsString('<sup id="fnref:1"><a class="footnote-ref" href="#fn:1"', $html);
        $this->assertStringContainsString('<div class="footnotes"', $html);
        $this->assertStringContainsString('<hr />', $html);
        $this->assertStringContainsString('<li class="footnote" id="fn:1"', $html);
        $this->assertStringContainsString('<a class="footnote-backref"', $html);
    }

    // --- heading IDs (item 6) --------------------------------------------------------------------

    #[Test]
    public function heading_ids_are_unique_in_document_order(): void
    {
        $rendered = $this->render("## A B\n\n## A-B\n\n## A B\n\n## A 2\n");

        $this->assertSame(['a-b', 'a-b-2', 'a-b-3', 'a-2'], self::ids($rendered));
        $this->assertStringContainsString('<h2 id="a-b">A B</h2>', $rendered->html);
        $this->assertStringContainsString('<h2 id="a-b-2">A-B</h2>', $rendered->html);
    }

    #[Test]
    public function a_literal_suffix_heading_after_duplicates_keeps_suffixing(): void
    {
        $this->assertSame(['a', 'a-2', 'a-2-2'], self::ids($this->render("## A\n\n## A\n\n## A 2\n")));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedIds(): iterable
    {
        foreach (['Main content', 'Site header', 'Theme button', 'Theme options', 'Reading progress'] as $heading) {
            yield $heading => [$heading];
        }
    }

    #[Test]
    #[DataProvider('reservedIds')]
    public function headings_never_take_a_layout_id(string $heading): void
    {
        $slug = str_replace(' ', '-', strtolower($heading));
        $rendered = $this->render("## {$heading}\n\n## {$heading}\n");

        $this->assertSame([$slug.'-2', $slug.'-3'], self::ids($rendered));
        $this->assertStringContainsString('<h2 id="'.$slug.'-2">'.$heading.'</h2>', $rendered->html);
    }

    #[Test]
    public function heading_text_is_the_literal_text_without_markup(): void
    {
        $rendered = $this->render("## Using `foo()` in **bold** and [a link](x.md) with ![alt text](i.png)\n");

        $this->assertSame('Using foo() in bold and a link with alt text', $rendered->headings[0]->text);
        $this->assertSame('using-foo-in-bold-and-a-link-with-alt-text', $rendered->headings[0]->id);
    }

    #[Test]
    public function slugs_keep_unicode_letters_and_fall_back_to_section(): void
    {
        $rendered = $this->render("## Çalışma Notları\n\n## !!!\n\n## ***\n\n## Ünïcode: 日本語 2\n");

        $this->assertSame(['çalışma-notları', 'section', 'section-2', 'ünïcode-日本語-2'], self::ids($rendered));
    }

    #[Test]
    public function the_outline_has_h2_and_h3_only_but_every_heading_gets_an_id(): void
    {
        $rendered = $this->render("# One\n\n## Two\n\n### Three\n\n#### Four\n\n## Five\n");

        $this->assertEquals(
            [new Heading(2, 'Two', 'two'), new Heading(3, 'Three', 'three'), new Heading(2, 'Five', 'five')],
            $rendered->headings,
        );
        $this->assertStringContainsString('<h1 id="one">', $rendered->html);
        $this->assertStringContainsString('<h4 id="four">', $rendered->html);
    }

    #[Test]
    public function documents_rendered_in_sequence_do_not_share_state(): void
    {
        $renderer = new MarkdownRenderer;
        $links = new RecordingLinkResolver;
        $first = $renderer->render("## Same\n\n## Same\n\n> [!NOTE]\n> x\n", 'content/a.md', $links);
        $second = $renderer->render("## Same\n\n## Same\n", 'content/b.md', $links);
        $third = $renderer->render("## Other\n", 'content/c.md', $links);

        $this->assertSame(['same', 'same-2'], self::ids($first));
        $this->assertSame(['same', 'same-2'], self::ids($second));
        $this->assertSame(['other'], self::ids($third));
        $this->assertSame($first->html, $renderer->render("## Same\n\n## Same\n\n> [!NOTE]\n> x\n", 'content/a.md', $links)->html);
    }

    // --- callouts (item 4) -----------------------------------------------------------------------

    #[Test]
    public function a_callout_without_a_title_uses_the_kind_name(): void
    {
        $html = $this->render("> [!TIP]\n> Use the cache.\n")->html;

        $this->assertSame(
            "<div class=\"callout callout-tip\"><p class=\"callout-title\">Tip</p>\n<p>Use the cache.</p></div>\n",
            $html,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function calloutKinds(): iterable
    {
        foreach (['NOTE' => 'Note', 'TIP' => 'Tip', 'IMPORTANT' => 'Important', 'WARNING' => 'Warning', 'CAUTION' => 'Caution'] as $kind => $title) {
            yield $kind => [$kind, $title];
        }
    }

    #[Test]
    #[DataProvider('calloutKinds')]
    public function every_kind_has_a_class_and_default_title(string $kind, string $title): void
    {
        $html = $this->render("> [!{$kind}]\n> Body.\n")->html;

        $this->assertStringContainsString('<div class="callout callout-'.strtolower($kind).'"><p class="callout-title">'.$title.'</p>', $html);
    }

    #[Test]
    public function a_callout_title_keeps_inline_formatting(): void
    {
        $html = $this->render("> [!WARNING] Mind the **bold** and `code`\n> Body line.\n")->html;

        $this->assertStringContainsString('<p class="callout-title">Mind the <strong>bold</strong> and <code>code</code></p>', $html);
        $this->assertStringContainsString("<p>Body line.</p></div>\n", $html);
    }

    #[Test]
    public function a_title_starting_with_formatting_is_kept(): void
    {
        $html = $this->render("> [!NOTE] **Heads up**\n> Body.\n")->html;

        $this->assertStringContainsString('<p class="callout-title"><strong>Heads up</strong></p>', $html);
    }

    #[Test]
    public function a_title_ends_at_a_hard_break(): void
    {
        $html = $this->render("> [!NOTE] Title  \n> continues here\n")->html;

        $this->assertStringContainsString('<p class="callout-title">Title</p>', $html);
        $this->assertStringContainsString('<p>continues here</p>', $html);
        $this->assertStringNotContainsString('<br', $html);

        $backslash = $this->render("> [!NOTE] Title\\\n> continues\n")->html;
        $this->assertStringContainsString('<p class="callout-title">Title</p>', $backslash);
        $this->assertStringContainsString('<p>continues</p>', $backslash);
    }

    #[Test]
    public function later_paragraphs_and_nested_blocks_stay_children(): void
    {
        $html = $this->render("> [!IMPORTANT] Read this\n> first line\n> second line\n>\n> - item one\n> - item two\n>\n> last paragraph\n")->html;

        $this->assertStringContainsString('<p class="callout-title">Read this</p>', $html);
        $this->assertStringContainsString("<p>first line\nsecond line</p>", $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>item two</li>', $html);
        $this->assertStringContainsString('<p>last paragraph</p></div>', $html);
        $this->assertSame(1, substr_count($html, 'class="callout '));
    }

    #[Test]
    public function the_emptied_marker_paragraph_is_dropped(): void
    {
        $html = $this->render("> [!NOTE] Only a title\n>\n> Second paragraph.\n")->html;

        $this->assertSame(
            "<div class=\"callout callout-note\"><p class=\"callout-title\">Only a title</p>\n<p>Second paragraph.</p></div>\n",
            $html,
        );
        $this->assertSame(
            "<div class=\"callout callout-note\"><p class=\"callout-title\">Note</p></div>\n",
            $this->render("> [!NOTE]\n")->html,
        );
    }

    #[Test]
    public function the_kind_is_case_insensitive(): void
    {
        foreach (['[!note]', '[!Note]', '[!NOTE]', '[!nOtE]'] as $marker) {
            $this->assertStringContainsString(
                '<div class="callout callout-note"><p class="callout-title">Note</p>',
                $this->render("> {$marker}\n> Body.\n")->html,
                $marker,
            );
        }
        $this->assertStringContainsString(
            '<div class="callout callout-warning"><p class="callout-title">Custom</p>',
            $this->render("> [!warning] Custom\n> Body.\n")->html,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notCallouts(): iterable
    {
        yield 'unknown kind' => ["> [!FOO] x\n> body\n"];
        yield 'no space after marker' => ["> [!NOTE]x\n> body\n"];
        yield 'marker later in the text' => ["> Text [!NOTE]\n> body\n"];
        yield 'plain quote' => ["> Just a quote.\n"];
        yield 'quote starting with a list' => ["> - [!NOTE]\n"];
    }

    #[Test]
    #[DataProvider('notCallouts')]
    public function other_blockquotes_stay_blockquotes(string $markdown): void
    {
        $html = $this->render($markdown)->html;

        $this->assertStringStartsWith('<blockquote>', $html);
        $this->assertStringNotContainsString('callout', $html);
    }

    #[Test]
    public function a_callout_title_is_escaped(): void
    {
        $html = $this->render("> [!NOTE] <script>alert(1)</script> & more\n> Body.\n")->html;

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('<p class="callout-title">&lt;script&gt;alert(1)&lt;/script&gt; &amp; more</p>', $html);
    }

    #[Test]
    public function callouts_nest_in_lists_and_can_hold_code(): void
    {
        $html = $this->render("- item\n\n  > [!TIP] Inside\n  > ```php\n  > echo 1;\n  > ```\n")->html;

        $this->assertStringContainsString('<div class="callout callout-tip"><p class="callout-title">Inside</p>', $html);
        $this->assertStringContainsString('<div class="code-block">', $html);
    }

    // --- figures (item 3) ------------------------------------------------------------------------

    #[Test]
    public function a_lone_titled_image_becomes_a_figure(): void
    {
        $html = $this->render("![A diagram](diagram.png \"Figure 1: the flow\")\n")->html;

        $this->assertSame('<figure><img src="diagram.png" alt="A diagram" /><figcaption>Figure 1: the flow</figcaption></figure>'."\n", $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notFigures(): iterable
    {
        yield 'no title' => ['![alt](a.png)'];
        yield 'empty title' => ['![alt](a.png "")'];
        yield 'text before' => ['See ![alt](a.png "Title")'];
        yield 'text after' => ['![alt](a.png "Title") done'];
        yield 'two images' => ['![a](a.png "A") ![b](b.png "B")'];
        yield 'image in a link' => ['[![alt](a.png "Title")](https://example.test/)'];
    }

    #[Test]
    #[DataProvider('notFigures')]
    public function other_images_stay_inline(string $markdown): void
    {
        $html = $this->render($markdown."\n")->html;

        $this->assertStringStartsWith('<p>', $html);
        $this->assertStringNotContainsString('<figure', $html);
    }

    #[Test]
    public function a_caption_is_escaped(): void
    {
        $html = $this->render("![x](a.png \"<script>alert(1)</script> & \\\"quoted\\\"\")\n")->html;

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('<figcaption>&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;quoted&quot;</figcaption>', $html);
        $this->assertStringNotContainsString('title=', $html);
    }

    #[Test]
    public function a_titled_image_with_an_unsafe_source_is_still_neutralised(): void
    {
        $html = $this->render("![x](javascript:alert(1) \"Caption\")\n")->html;

        $this->assertSame('<figure><img src="" alt="x" /><figcaption>Caption</figcaption></figure>'."\n", $html);
    }

    #[Test]
    public function a_figure_uses_the_rewritten_image_url(): void
    {
        $links = new RecordingLinkResolver(static fn (string $url): string => '/notes/'.$url);
        $html = $this->render("![y](<a b.png> \"Cap\")\n", $links)->html;

        $this->assertSame('<figure><img src="/notes/a%20b.png" alt="y" /><figcaption>Cap</figcaption></figure>'."\n", $html);
    }

    #[Test]
    public function figures_inside_callouts_work(): void
    {
        $html = $this->render("> [!NOTE]\n> ![x](a.png \"Cap\")\n")->html;

        $this->assertStringContainsString('<div class="callout callout-note"><p class="callout-title">Note</p>', $html);
        $this->assertStringContainsString('<figure><img src="a.png" alt="x" /><figcaption>Cap</figcaption></figure>', $html);
    }

    // --- link rewriting hook (item 7) ------------------------------------------------------------

    #[Test]
    public function every_link_and_image_goes_through_the_resolver_once(): void
    {
        $links = new RecordingLinkResolver(static fn (string $url): string => 'R:'.$url);
        $markdown = "[a](one.md) ![b](two.png \"T\")\n\n> [!NOTE] [c](three.md)\n> [d](four.md)\n\nFootnote[^1].\n\n[^1]: See [e](five.md).\n\n[ref]: six.md\n\n[f][ref] <https://example.test/>\n";
        $html = $this->render($markdown, $links)->html;

        $urls = array_map(static fn (array $call): string => $call[1], $links->calls);
        sort($urls);
        $this->assertSame(['five.md', 'four.md', 'https://example.test/', 'one.md', 'six.md', 'three.md', 'two.png'], $urls);
        $this->assertContains(['content/x.md', 'two.png', true], $links->calls);
        $this->assertContains(['content/x.md', 'one.md', false], $links->calls);
        $this->assertStringContainsString('href="R:one.md"', $html);
        $this->assertStringContainsString('href="R:six.md"', $html);
        $this->assertStringContainsString('href="R:five.md"', $html);
        $this->assertStringContainsString('src="R:two.png"', $html);
    }

    #[Test]
    public function resolver_errors_propagate(): void
    {
        $links = new class implements LinkResolver
        {
            public function resolve(string $sourcePath, string $url, bool $isImage): string
            {
                throw new ContentException('link "'.$url.'" is broken.', $sourcePath);
            }
        };

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('content error in content/x.md: link "nope.md" is broken.');

        $this->render("[x](nope.md)\n", $links);
    }

    // --- word count and reading time (item 8) ----------------------------------------------------

    #[Test]
    public function words_are_counted_on_the_rendered_text(): void
    {
        $rendered = $this->render("# Title here\n\nOne *two* **three** [four](x.md) `five`.\n\n- six\n- seven &amp; eight\n");

        // Title here (2) + One two three four five (5) + six, seven, eight (3); the lone "&" is not a word.
        $this->assertSame(10, $rendered->wordCount);
    }

    #[Test]
    public function inline_markup_inside_a_word_does_not_split_it(): void
    {
        $markdown = implode(' ', array_fill(0, 100, 'un**break**able'))."\n";

        $this->assertSame(100, $this->render($markdown)->wordCount);
        $this->assertSame(3, $this->render("one *two*three [four](x.md)five\n")->wordCount);
    }

    #[Test]
    public function block_tags_separate_words(): void
    {
        $this->assertSame(6, $this->render("- one\n- two\n\nthree\n\n> four\n\n| a | b |\n|---|---|\n")->wordCount);
    }

    #[Test]
    public function reading_time_is_ceil_of_words_over_200_with_a_minimum_of_one(): void
    {
        $words = static fn (int $n): string => implode(' ', array_fill(0, $n, 'word'))."\n";

        $this->assertSame(1, $this->render('')->readingMinutes());
        $this->assertSame(0, $this->render('')->wordCount);
        $this->assertSame(1, $this->render($words(200))->readingMinutes());
        $this->assertSame(2, $this->render($words(201))->readingMinutes());
        $this->assertSame(3, $this->render($words(401))->readingMinutes());
    }

    #[Test]
    public function an_empty_document_renders_nothing(): void
    {
        $rendered = $this->render('');

        $this->assertSame('', $rendered->html);
        $this->assertSame([], $rendered->headings);
    }
}
