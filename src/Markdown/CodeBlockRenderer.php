<?php

declare(strict_types=1);

namespace Astro\Verse\Markdown;

use InvalidArgumentException;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Util\Xml;
use Tempest\Highlight\Highlighter;

/**
 * Fenced and indented code: the default theme's code-block markup. The code is never interpreted:
 * it is escaped, or highlighted by tempest/highlight (which escapes it and adds `hl-*` spans).
 */
final class CodeBlockRenderer implements NodeRendererInterface
{
    /**
     * Info-string word => [display name, tempest/highlight language].
     *
     * @var array<string, array{string, string}>
     */
    private const array LANGUAGES = [
        'php' => ['PHP', 'php'],
        'blade' => ['Blade', 'blade'],
        'js' => ['JavaScript', 'javascript'],
        'javascript' => ['JavaScript', 'javascript'],
        'ts' => ['TypeScript', 'typescript'],
        'typescript' => ['TypeScript', 'typescript'],
        'json' => ['JSON', 'json'],
        'yaml' => ['YAML', 'yaml'],
        'yml' => ['YAML', 'yaml'],
        'html' => ['HTML', 'html'],
        'css' => ['CSS', 'css'],
        'sql' => ['SQL', 'sql'],
        'bash' => ['Shell', 'bash'],
        'sh' => ['Shell', 'bash'],
        'shell' => ['Shell', 'bash'],
        'zsh' => ['Shell', 'bash'],
        'diff' => ['Diff', 'diff'],
        'xml' => ['XML', 'xml'],
        'markdown' => ['Markdown', 'markdown'],
        'md' => ['Markdown', 'markdown'],
        'text' => ['Text', 'text'],
        'txt' => ['Text', 'text'],
        'python' => ['Python', 'python'],
        'py' => ['Python', 'python'],
        'ruby' => ['Ruby', 'ruby'],
        'rb' => ['Ruby', 'ruby'],
        'swift' => ['Swift', 'swift'],
        'scss' => ['SCSS', 'scss'],
        'dockerfile' => ['Dockerfile', 'dockerfile'],
        'docker' => ['Dockerfile', 'dockerfile'],
        'graphql' => ['GraphQL', 'graphql'],
        'gql' => ['GraphQL', 'graphql'],
        'ini' => ['INI', 'ini'],
        'nginx' => ['Nginx', 'nginx'],
        'apache' => ['Apache', 'apache'],
        'twig' => ['Twig', 'twig'],
        'vue' => ['Vue', 'vue'],
        'jsx' => ['JSX', 'jsx'],
        'tsx' => ['TSX', 'tsx'],
        'svelte' => ['Svelte', 'svelte'],
        'terraform' => ['Terraform', 'terraform'],
        'tf' => ['Terraform', 'terraform'],
        'dotenv' => ['.env', 'dotenv'],
        'env' => ['.env', 'dotenv'],
        'http' => ['HTTP', 'http'],
        'gdscript' => ['GDScript', 'gdscript'],
    ];

    /**
     * tempest/highlight rewrites code that contains its own markers (`{+ +}`, `{- -}`, `{~ ~}`, `{_ _}`,
     * `{* *}`, `{:class:…:}`), also inside embedded languages, so each opening `{` of such a marker is
     * broken with this private-use character, which is removed from the output again.
     */
    private const string BREAK = "\u{E000}";

    public function __construct(private readonly Highlighter $highlighter) {}

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        if ($node instanceof FencedCode) {
            $words = $node->getInfoWords();
            $word = strtolower($words[0] ?? '');
            $literal = $node->getLiteral();
        } elseif ($node instanceof IndentedCode) {
            $word = '';
            $literal = $node->getLiteral();
        } else {
            throw new InvalidArgumentException('Unexpected node '.$node::class);
        }

        $code = str_ends_with($literal, "\n") ? substr($literal, 0, -1) : $literal;
        $known = self::LANGUAGES[$word] ?? null;

        if ($known === null) {
            $label = 'Code';
            $attributes = [];
            $html = Xml::escape($code);
        } else {
            $label = $known[0];
            $attributes = ['class' => 'language-'.$word];
            $html = $this->highlight($code, $known[1]);
        }

        return new HtmlElement('div', ['class' => 'code-block'], new HtmlElement(
            'div',
            ['class' => 'code-header'],
            new HtmlElement('span', [], Xml::escape($label)),
        ).new HtmlElement(
            'pre',
            ['tabindex' => '0', 'aria-label' => 'Code example'],
            new HtmlElement('code', $attributes, $html),
        ));
    }

    /**
     * Highlighted markup of `$code` whose text is exactly `$code`; plain escaped text when the code contains
     * a character the highlighter uses internally (its own token keys or the break character).
     */
    private function highlight(string $code, string $language): string
    {
        if (preg_match('/[❶❷❸❹❿\x{E000}]/u', $code) === 1) {
            return Xml::escape($code);
        }

        $broken = preg_replace('/\{(?=[+\-~_*:])/', '{'.self::BREAK, $code) ?? $code;

        return str_replace(self::BREAK, '', $this->highlighter->parse($broken, $language));
    }
}
