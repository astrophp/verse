<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Config;

use Astro\Verse\Config\ConfigLoader;
use Astro\Verse\Config\SiteConfig;
use Astro\Verse\Error\ConfigException;
use Astro\Verse\Error\ErrorKind;
use Astro\Verse\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class ConfigLoaderTest extends TestCase
{
    private function parse(string $yaml, bool $requireUrl = false, string $root = '/nonexistent'): SiteConfig
    {
        return (new ConfigLoader)->parseSite($yaml, $root, $requireUrl);
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function assertConfigError(callable $callback, ?string $field, string $messagePart = ''): void
    {
        try {
            $callback();
        } catch (ConfigException $exception) {
            $this->assertSame(ErrorKind::Config, $exception->kind());
            $this->assertSame($field, $exception->field, $exception->getMessage());
            $this->assertSame('verse.yaml', $exception->sourceFile);
            $this->assertStringContainsString($messagePart, $exception->getMessage());
            $this->assertStringStartsWith('config error in verse.yaml', $exception->getMessage());

            return;
        }
        $this->fail('Expected a ConfigException for field '.$field);
    }

    #[Test]
    public function it_applies_defaults_for_a_minimal_file(): void
    {
        $config = $this->parse("title: Notes\n");

        $this->assertSame('Notes', $config->title);
        $this->assertNull($config->url);
        $this->assertSame('/', $config->basePath());
        $this->assertNull($config->description);
        $this->assertNull($config->author);
        $this->assertSame([], $config->socialLinks);
        $this->assertSame('en', $config->language);
        $this->assertSame('UTC', $config->timezone);
        $this->assertSame('default', $config->theme->name);
        $this->assertTrue($config->theme->toc);
        $this->assertNull($config->theme->logo);
        $this->assertNull($config->theme->accentLight);
        $this->assertNull($config->theme->accentDark);
        $this->assertSame([], $config->extra);
    }

    #[Test]
    public function the_sitemap_is_on_by_default_and_accepts_a_boolean(): void
    {
        $this->assertTrue($this->parse("title: x\n")->sitemap);
        $this->assertTrue($this->parse("title: x\nsitemap: true\n")->sitemap);
        $this->assertFalse($this->parse("title: x\nsitemap: false\n")->sitemap);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSitemapValues(): array
    {
        return [
            'quoted true' => ['"true"'],
            'yes as a string' => ['yes'],
            'a number' => ['1'],
            'empty' => [''],
            'a list' => ['[true]'],
            'a map' => ['{enabled: true}'],
        ];
    }

    #[Test]
    #[DataProvider('invalidSitemapValues')]
    public function it_rejects_a_sitemap_that_is_not_a_boolean(string $value): void
    {
        $this->assertConfigError(
            fn () => $this->parse("title: x\nsitemap: ".$value."\n"),
            'sitemap',
            'field "sitemap": expected true or false',
        );
    }

    #[Test]
    public function it_loads_a_full_file(): void
    {
        $config = $this->parse(<<<'YAML'
            title: Jane Doe
            url: https://jane.example.net/
            description: Notes
            language: tr
            timezone: Europe/Istanbul
            extra:
              analytics: abc
              nested: {a: 1}
            YAML);

        $this->assertSame('Notes', $config->description);
        $this->assertSame('tr', $config->language);
        $this->assertSame('Europe/Istanbul', $config->dateTimeZone()->getName());
        $this->assertSame(['analytics' => 'abc', 'nested' => ['a' => 1]], $config->extra);
    }

    #[Test]
    public function it_requires_a_title(): void
    {
        $this->assertConfigError(fn () => $this->parse("description: x\n"), 'title', 'required');
        $this->assertConfigError(fn () => $this->parse("title: ''\n"), 'title', 'empty');
        $this->assertConfigError(fn () => $this->parse("title: 5\n"), 'title', 'expected a string');
        $this->assertConfigError(fn () => $this->parse("title: [a]\n"), 'title', 'expected a string');
    }

    #[Test]
    public function it_rejects_unknown_keys_naming_the_key_path(): void
    {
        $this->assertConfigError(fn () => $this->parse("title: x\ncolour: red\n"), 'colour', 'unknown key "colour"');
        $this->assertConfigError(fn () => $this->parse("title: x\ntheme:\n  colour: red\n"), 'theme.colour', 'unknown key "theme.colour"');
        $this->assertConfigError(fn () => $this->parse("title: x\nauthor:\n  name: A\n  links: {}\n"), 'author.links', 'unknown key "author.links"');
        $this->assertConfigError(fn () => $this->parse("title: x\ntheme:\n  accent:\n    mid: '#fff'\n"), 'theme.accent.mid');
    }

    #[Test]
    public function articles_are_not_configurable(): void
    {
        // an ordinary unknown key, with no special hint
        $this->assertConfigError(fn () => $this->parse("title: x\narticles:\n  per_page: 5\n"), 'articles', 'unknown key "articles" (put custom values under "extra").');
    }

    #[Test]
    public function it_suggests_extra_for_unknown_keys(): void
    {
        $this->assertConfigError(fn () => $this->parse("title: x\nanalytics: 1\n"), 'analytics', 'extra');
    }

    #[Test]
    public function it_requires_the_url_only_when_asked(): void
    {
        $this->assertNull($this->parse("title: x\n", requireUrl: false)->url);
        $this->assertConfigError(fn () => $this->parse("title: x\n", requireUrl: true), 'url', 'required');
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function validUrls(): iterable
    {
        yield 'root' => ['https://example.test', 'https://example.test', '/', 'https://example.test/'];
        yield 'root slash' => ['https://example.test/', 'https://example.test', '/', 'https://example.test/'];
        yield 'host lowercased' => ['HTTPS://Example.TEST/', 'https://example.test', '/', 'https://example.test/'];
        yield 'base path' => ['https://example.test/notes', 'https://example.test', '/notes/', 'https://example.test/notes/'];
        yield 'base path slash' => ['https://example.test/notes/', 'https://example.test', '/notes/', 'https://example.test/notes/'];
        yield 'nested base path' => ['http://example.test/a/b/', 'http://example.test', '/a/b/', 'http://example.test/a/b/'];
        yield 'port' => ['http://localhost:8000/', 'http://localhost:8000', '/', 'http://localhost:8000/'];
        yield 'port 8080' => ['https://example.test:8080/', 'https://example.test:8080', '/', 'https://example.test:8080/'];
        yield 'max port' => ['https://example.test:65535/', 'https://example.test:65535', '/', 'https://example.test:65535/'];
        yield 'unicode host' => ['https://münchen.example/', 'https://münchen.example', '/', 'https://münchen.example/'];
        yield 'localhost' => ['http://localhost/', 'http://localhost', '/', 'http://localhost/'];
        yield 'ipv4' => ['http://127.0.0.1:8000/x', 'http://127.0.0.1:8000', '/x/', 'http://127.0.0.1:8000/x/'];
        yield 'unicode base path' => ['https://example.test/çalışma', 'https://example.test', '/çalışma/', 'https://example.test/çalışma/'];
    }

    #[Test]
    #[DataProvider('validUrls')]
    public function it_normalizes_the_url(string $input, string $origin, string $basePath, string $full): void
    {
        $config = $this->parse("title: x\nurl: \"{$input}\"\n", requireUrl: true);

        $this->assertNotNull($config->url);
        $this->assertSame($origin, $config->url->origin);
        $this->assertSame($basePath, $config->url->basePath);
        $this->assertSame($full, $config->url->full());
        $this->assertSame($basePath, $config->basePath());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'query' => ['https://example.test/?a=1'];
        yield 'fragment' => ['https://example.test/#top'];
        yield 'userinfo' => ['https://user:pw@example.test/'];
        yield 'user only' => ['https://user@example.test/'];
        yield 'ftp' => ['ftp://example.test/'];
        yield 'relative' => ['/notes/'];
        yield 'no scheme' => ['example.test'];
        yield 'protocol relative' => ['//example.test/'];
        yield 'percent' => ['https://example.test/a%20b/'];
        yield 'dot segment' => ['https://example.test/./a/'];
        yield 'dot dot segment' => ['https://example.test/a/../'];
        yield 'empty segment' => ['https://example.test/a//b/'];
        yield 'double trailing slash' => ['https://example.test/a//'];
        yield 'space' => ['https://example.test/a b/'];
        yield 'no host' => ['https:///notes/'];
        yield 'bad host' => ['https://exa_mple.test/'];
        yield 'backslash' => ['https://example.test\\a/'];
        yield 'colon only' => ['https://:'];
        yield 'empty host with port' => ['https://:80/'];
        yield 'port zero' => ['https://example.test:0/'];
        yield 'port too large' => ['https://example.test:99999/'];
        yield 'port not numeric' => ['https://example.test:http/'];
        yield 'space in host' => ['https://exa mple.test/'];
        yield 'leading hyphen label' => ['https://-bad.test/'];
        yield 'trailing hyphen label' => ['https://bad-.test/'];
        yield 'empty label' => ['https://bad..test/'];
        yield 'trailing dot colon' => ['https://example.test.:/'];
    }

    #[Test]
    #[DataProvider('invalidUrls')]
    public function it_rejects_malformed_urls(string $url): void
    {
        $yaml = 'title: x'."\n".'url: '.json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        $this->assertConfigError(fn () => $this->parse($yaml), 'url');
    }

    #[Test]
    public function it_rejects_a_non_string_url(): void
    {
        $this->assertConfigError(fn () => $this->parse("title: x\nurl: 5\n"), 'url', 'expected a string');
    }

    #[Test]
    public function it_accepts_the_author_as_a_string_or_a_map(): void
    {
        $string = $this->parse("title: x\nauthor: Jane\n");
        $this->assertNotNull($string->author);
        $this->assertSame('Jane', $string->author->name);

        $map = $this->parse(<<<'YAML'
            title: x
            author:
              name: Zoë Doe
              url: https://jane.example.net/
              email: me@example.test
            YAML);
        $this->assertNotNull($map->author);
        $this->assertSame('Zoë Doe', $map->author->name);
        $this->assertSame('https://jane.example.net/', $map->author->url);
        $this->assertSame('me@example.test', $map->author->email);
    }

    #[Test]
    public function it_reads_the_tagline(): void
    {
        $this->assertNull($this->parse("title: x\n")->tagline);
        $this->assertSame('Writer & Engineer', $this->parse("title: x\ntagline: Writer & Engineer\n")->tagline);
        $this->assertConfigError(fn () => $this->parse("title: x\ntagline: ''\n"), 'tagline', 'empty');
        $this->assertConfigError(fn () => $this->parse("title: x\ntagline: 5\n"), 'tagline', 'expected a string');
        $this->assertConfigError(fn () => $this->parse("title: x\ntagline: [a]\n"), 'tagline', 'expected a string');
    }

    #[Test]
    public function it_validates_the_author(): void
    {
        $this->assertConfigError(fn () => $this->parse("title: x\nauthor:\n  url: https://example.test/\n"), 'author.name', 'required');
        $this->assertConfigError(fn () => $this->parse("title: x\nauthor:\n  name: A\n  title: Engineer\n"), 'author.title', 'unknown key "author.title"');
        $this->assertConfigError(fn () => $this->parse("title: x\nauthor: ''\n"), 'author', 'empty');
        $this->assertConfigError(fn () => $this->parse("title: x\nauthor: [a, b]\n"), 'author');
        $this->assertConfigError(fn () => $this->parse("title: x\nauthor: 5\n"), 'author');
        $this->assertConfigError(fn () => $this->parse("title: x\nauthor:\n  name: A\n  url: nope\n"), 'author.url');
        $this->assertConfigError(fn () => $this->parse("title: x\nauthor:\n  name: A\n  email: nope\n"), 'author.email');
        $this->assertConfigError(fn () => $this->parse("title: x\nauthor:\n  name: 5\n"), 'author.name');
    }

    #[Test]
    public function it_keeps_social_link_order_and_maps_labels(): void
    {
        $config = $this->parse(<<<'YAML'
            title: x
            social_links:
              medium: https://medium.com/@janedoe
              github: https://github.com/janedoe
              linkedin: https://www.linkedin.com/in/janedoe/
              x: https://x.com/janedoe
              mastodon: https://mastodon.social/@janedoe
              bluesky: https://bsky.app/profile/janedoe
              youtube: https://youtube.com/@janedoe
              email: mailto:me@example.test
              dribbble: https://dribbble.com/janedoe
            YAML);

        $keys = array_map(static fn ($link) => $link->key, $config->socialLinks);
        $labels = array_map(static fn ($link) => $link->label, $config->socialLinks);

        $this->assertSame(['medium', 'github', 'linkedin', 'x', 'mastodon', 'bluesky', 'youtube', 'email', 'dribbble'], $keys);
        $this->assertSame(['Medium', 'GitHub', 'LinkedIn', 'X', 'Mastodon', 'Bluesky', 'YouTube', 'Email', 'Dribbble'], $labels);
        $this->assertSame('mailto:me@example.test', $config->socialLinks[7]->url);
        $this->assertSame('https://medium.com/@janedoe', $config->socialLinks[0]->url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSocialLinks(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'bare host' => ['github.com/janedoe'];
        yield 'ftp' => ['ftp://example.test/x'];
        yield 'empty mailto' => ['mailto:'];
        yield 'spaces' => ['https://example.test/a b'];
    }

    #[Test]
    #[DataProvider('invalidSocialLinks')]
    public function it_rejects_invalid_social_link_urls(string $url): void
    {
        $this->assertConfigError(
            fn () => $this->parse("title: x\nsocial_links:\n  github: ".json_encode($url)."\n"),
            'social_links.github',
        );
    }

    #[Test]
    public function it_requires_social_links_to_be_a_top_level_map(): void
    {
        $this->assertConfigError(fn () => $this->parse("title: x\nsocial_links: [https://github.com/a]\n"), 'social_links', 'expected a mapping');
        $this->assertConfigError(fn () => $this->parse("title: x\nsocial_links:\n  github: 5\n"), 'social_links.github');
        $this->assertSame([], $this->parse("title: x\nsocial_links: {}\n")->socialLinks);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function languages(): iterable
    {
        yield 'en' => ['en', true];
        yield 'tr' => ['tr', true];
        yield 'pt-BR' => ['pt-BR', true];
        yield 'zh-Hant-TW' => ['zh-Hant-TW', true];
        yield 'long word' => ['english', false];
        yield 'one letter' => ['e', false];
        yield 'underscore' => ['en_US', false];
        yield 'trailing dash' => ['en-', false];
        yield 'digits' => ['12', false];
    }

    #[Test]
    #[DataProvider('languages')]
    public function it_validates_the_language_tag(string $language, bool $valid): void
    {
        $yaml = "title: x\nlanguage: \"{$language}\"\n";
        if ($valid) {
            $this->assertSame($language, $this->parse($yaml)->language);

            return;
        }
        $this->assertConfigError(fn () => $this->parse($yaml), 'language');
    }

    #[Test]
    public function it_validates_the_timezone(): void
    {
        $this->assertSame('UTC', $this->parse("title: x\ntimezone: UTC\n")->timezone);
        $this->assertSame('America/New_York', $this->parse("title: x\ntimezone: America/New_York\n")->timezone);
        $this->assertConfigError(fn () => $this->parse("title: x\ntimezone: Mars/Base\n"), 'timezone');
        $this->assertConfigError(fn () => $this->parse("title: x\ntimezone: istanbul\n"), 'timezone');
        $this->assertConfigError(fn () => $this->parse("title: x\ntimezone: 3\n"), 'timezone');
    }

    #[Test]
    public function it_reads_theme_settings(): void
    {
        $config = $this->parse("title: x\ntheme:\n  toc: false\n  accent: '#ff0044'\n  logo: https://example.test/logo.svg\n");

        $this->assertFalse($config->theme->toc);
        $this->assertSame('#ff0044', $config->theme->accentLight);
        $this->assertSame('#ff0044', $config->theme->accentDark);
        $this->assertSame('https://example.test/logo.svg', $config->theme->logo);
    }

    #[Test]
    public function it_defaults_and_accepts_the_theme_name(): void
    {
        $this->assertSame('default', $this->parse("title: x\ntheme:\n  toc: false\n")->theme->name);
        $this->assertSame('default', $this->parse("title: x\n")->theme->name);

        $themes = $this->themeRegistry(['paper-2/theme.yaml' => "name: paper-2\nentries: [app.css]\n"]);
        $this->assertSame('paper-2', (new ConfigLoader($themes))->parseSite("title: x\ntheme:\n  name: paper-2\n", '/nonexistent', false)->theme->name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidThemeNames(): iterable
    {
        yield 'uppercase' => ['Default'];
        yield 'leading dash' => ['-paper'];
        yield 'underscore' => ['my_theme'];
        yield 'path' => ['../escape'];
        yield 'space' => ['my theme'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('invalidThemeNames')]
    public function it_rejects_invalid_theme_names(string $name): void
    {
        $this->assertConfigError(
            fn () => $this->parse("title: x\ntheme:\n  name: ".json_encode($name)."\n"),
            'theme.name',
        );
    }

    #[Test]
    public function it_requires_a_string_theme_name(): void
    {
        $this->assertConfigError(fn () => $this->parse("title: x\ntheme:\n  name: 5\n"), 'theme.name', 'expected a string');
    }

    #[Test]
    public function it_requires_theme_toc_to_be_boolean(): void
    {
        $this->assertConfigError(fn () => $this->parse("title: x\ntheme:\n  toc: yes please\n"), 'theme.toc');
        $this->assertConfigError(fn () => $this->parse("title: x\ntheme:\n  toc: 1\n"), 'theme.toc');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validColors(): iterable
    {
        yield 'short' => ['#abc'];
        yield 'long' => ['#A1B2C3'];
        yield 'alpha' => ['#aabbccdd'];
    }

    #[Test]
    #[DataProvider('validColors')]
    public function it_accepts_hex_accent_colors(string $color): void
    {
        $this->assertSame($color, $this->parse("title: x\ntheme:\n  accent: \"{$color}\"\n")->theme->accentLight);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidColors(): iterable
    {
        yield 'css injection' => ['red;}</style><script>alert(1)</script>'];
        yield 'injection with hash' => ['#fff;}</style>'];
        yield 'name' => ['red'];
        yield 'rgb' => ['rgb(1, 2, 3)'];
        yield 'hsl' => ['hsl(10 20% 30%)'];
        yield 'too short' => ['#12'];
        yield 'five digits' => ['#12345'];
        yield 'non hex' => ['#ggg'];
        yield 'no hash' => ['ff0044'];
        yield 'var' => ['var(--x)'];
    }

    #[Test]
    #[DataProvider('invalidColors')]
    public function it_rejects_non_hex_accents(string $color): void
    {
        $this->assertConfigError(
            fn () => $this->parse("title: x\ntheme:\n  accent: ".json_encode($color)."\n"),
            'theme.accent',
            'hex colour',
        );
    }

    #[Test]
    public function it_accepts_a_partial_accent_map(): void
    {
        $dark = $this->parse("title: x\ntheme:\n  accent:\n    dark: '#88aaff'\n")->theme;
        $this->assertNull($dark->accentLight);
        $this->assertSame('#88aaff', $dark->accentDark);

        $both = $this->parse("title: x\ntheme:\n  accent:\n    light: '#112233'\n    dark: '#445566'\n")->theme;
        $this->assertSame('#112233', $both->accentLight);
        $this->assertSame('#445566', $both->accentDark);

        $nulls = $this->parse("title: x\ntheme:\n  accent:\n    light: ~\n")->theme;
        $this->assertNull($nulls->accentLight);
        $this->assertNull($nulls->accentDark);
    }

    #[Test]
    public function it_validates_accent_map_values(): void
    {
        $this->assertConfigError(fn () => $this->parse("title: x\ntheme:\n  accent:\n    dark: 'red;}'\n"), 'theme.accent.dark');
        $this->assertConfigError(fn () => $this->parse("title: x\ntheme:\n  accent:\n    light: 5\n"), 'theme.accent.light');
        $this->assertConfigError(fn () => $this->parse("title: x\ntheme:\n  accent: 5\n"), 'theme.accent');
        $this->assertConfigError(fn () => $this->parse("title: x\ntheme:\n  accent: ['#fff']\n"), 'theme.accent');
    }

    #[Test]
    public function it_validates_the_logo(): void
    {
        $root = $this->makeSite(['public/icon.svg' => '<svg/>']);

        $this->assertSame('/icon.svg', $this->parse("title: x\ntheme:\n  logo: /icon.svg\n", root: $root)->theme->logo);
        $this->assertNull($this->parse("title: x\ntheme:\n  logo: ~\n", root: $root)->theme->logo);

        $this->assertConfigError(fn () => $this->parse("title: x\ntheme:\n  logo: /missing.svg\n", root: $root), 'theme.logo', 'public/missing.svg');
        $this->assertConfigError(fn () => $this->parse("title: x\ntheme:\n  logo: icon.svg\n", root: $root), 'theme.logo');
        $this->assertConfigError(fn () => $this->parse("title: x\ntheme:\n  logo: /../icon.svg\n", root: $root), 'theme.logo');
        $this->assertConfigError(fn () => $this->parse("title: x\ntheme:\n  logo: //evil.test/x.svg\n", root: $root), 'theme.logo');
        $this->assertConfigError(fn () => $this->parse("title: x\ntheme:\n  logo: javascript:alert(1)\n", root: $root), 'theme.logo');
    }

    #[Test]
    public function it_requires_extra_and_theme_to_be_maps(): void
    {
        $this->assertConfigError(fn () => $this->parse("title: x\nextra: [a]\n"), 'extra', 'expected a mapping');
        $this->assertConfigError(fn () => $this->parse("title: x\ntheme: nope\n"), 'theme', 'expected a mapping');
    }

    #[Test]
    public function it_treats_yaml_as_data_only(): void
    {
        $this->assertConfigError(fn () => $this->parse("title: !custom Notes\n"), null, 'Tags support is not enabled');
        $this->assertConfigError(fn () => $this->parse("title: x\nextra:\n  o: !php/object 'O:8:\"stdClass\":0:{}'\n"), null, 'Object support');
    }

    #[Test]
    public function it_reports_invalid_yaml_and_wrong_document_shapes(): void
    {
        $this->assertConfigError(fn () => $this->parse("title: [unclosed\n"), null, 'invalid YAML');
        $this->assertConfigError(fn () => $this->parse("- a\n- b\n"), null, 'mapping');
        $this->assertConfigError(fn () => $this->parse("just a string\n"), null, 'mapping');
        $this->assertConfigError(fn () => $this->parse(''), 'title', 'required');
        $this->assertConfigError(fn () => $this->parse("# only a comment\n"), 'title', 'required');
        $this->assertConfigError(fn () => $this->parse("title: a\ntitle: b\n"), null, 'Duplicate key');
    }

    #[Test]
    public function it_fails_when_verse_yaml_is_missing(): void
    {
        $root = $this->makeSite();

        try {
            (new ConfigLoader)->load($root, requireUrl: false);
            $this->fail('Expected a ConfigException');
        } catch (ConfigException $exception) {
            $this->assertStringContainsString('verse.yaml was not found', $exception->getMessage());
            $this->assertSame('verse.yaml', $exception->sourceFile);
        }
    }

    #[Test]
    public function it_loads_verse_yaml_from_a_site_root(): void
    {
        $root = $this->makeSite(['verse.yaml' => "title: From Disk\nurl: https://example.test/notes\n"]);

        $config = (new ConfigLoader)->load($root, requireUrl: true);

        $this->assertSame('From Disk', $config->title);
        $this->assertSame('/notes/', $config->basePath());
    }
}
