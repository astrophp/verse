<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Build;

use Astro\Verse\Build\BuildContext;
use Astro\Verse\Build\Builder;
use Astro\Verse\Build\BuildMode;
use Astro\Verse\Build\LocalFilesystem;
use Astro\Verse\Config\ConfigLoader;
use Astro\Verse\Error\ContentException;
use Astro\Verse\Tests\Support\TestCase;
use Astro\Verse\Theme\ThemeRegistry;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * `sitemap.xml`: what it lists, when it is written and what it collides with. There is no `robots.txt`.
 */
final class SitemapTest extends TestCase
{
    private const string NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private const string NOW = '2026-10-20T12:00:00Z';

    private static function yaml(string $url = 'https://example.test/', string $extra = '', bool $sitemap = true): string
    {
        return "title: Notes\nurl: ".$url."\n".'sitemap: '.($sitemap ? 'true' : 'false')."\n".$extra;
    }

    /**
     * @param  array<string, ?string>  $changes
     */
    private function site(array $changes = [], ?string $yaml = null): string
    {
        return $this->fixtureSite('notes', ['verse.yaml' => $yaml ?? self::yaml()] + $changes);
    }

    private function read(string $root, string $path): string
    {
        $contents = file_get_contents($root.'/dist/'.$path);
        $this->assertIsString($contents);

        return $contents;
    }

    /**
     * @return array<string, ?string> every `loc` of `dist/sitemap.xml` in order, with its `lastmod`
     */
    private function entries(string $root): array
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($this->read($root, 'sitemap.xml')));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', self::NAMESPACE);

        $entries = [];
        foreach ($xpath->query('/s:urlset/s:url') ?: [] as $url) {
            $this->assertInstanceOf(DOMElement::class, $url);
            $loc = $xpath->evaluate('string(s:loc)', $url);
            $this->assertIsString($loc);
            $this->assertArrayNotHasKey($loc, $entries, 'every loc is unique');
            $lastmod = $xpath->query('s:lastmod', $url);
            $this->assertNotFalse($lastmod);
            $this->assertLessThanOrEqual(1, $lastmod->length);
            $entries[$loc] = $lastmod->length === 0 ? null : $xpath->evaluate('string(s:lastmod)', $url);
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function locs(string $root): array
    {
        return array_keys($this->entries($root));
    }

    #[Test]
    public function the_sitemap_is_written_by_default(): void
    {
        $root = $this->site([], "title: Notes\nurl: https://example.test/\n");
        $this->buildSite($root);

        $this->assertFileExists($root.'/dist/sitemap.xml');
        $this->assertFileDoesNotExist($root.'/dist/robots.txt');
    }

    #[Test]
    public function sitemap_false_writes_none(): void
    {
        $root = $this->site([], self::yaml(sitemap: false));
        $this->buildSite($root);

        $this->assertFileDoesNotExist($root.'/dist/sitemap.xml');
        $this->assertFileDoesNotExist($root.'/dist/robots.txt');
    }

    #[Test]
    public function the_notes_site_lists_its_published_pages_in_rendering_order(): void
    {
        $root = $this->site();
        $this->buildSite($root);

        $this->assertSame([
            'https://example.test/',
            'https://example.test/about/',
            'https://example.test/legal/privacy/',
            'https://example.test/projects/',
            'https://example.test/articles/',
            'https://example.test/essays/trace/',
            'https://example.test/articles/older-note/',
            'https://example.test/tags/',
            'https://example.test/tags/ai-systems/',
            'https://example.test/tags/laravel/',
            'https://example.test/tags/observability/',
        ], $this->locs($root));
        $this->assertFileDoesNotExist($root.'/dist/robots.txt');
        $this->assertStringStartsWith("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"".self::NAMESPACE."\">\n  <url>\n    <loc>https://example.test/</loc>\n  </url>\n", $this->read($root, 'sitemap.xml'));
        $this->assertStringEndsWith("  </url>\n</urlset>\n", $this->read($root, 'sitemap.xml'));
    }

    #[Test]
    public function under_a_base_path_every_url_carries_it_and_special_characters_are_encoded_and_escaped(): void
    {
        $root = $this->site([
            'content/articles/tea-time.md' => self::markdown('Tea', ['date' => '2026-02-01', 'slug' => 'tea-time']),
            'content/Café & Tea.md' => self::markdown('Café'),
        ], self::yaml('https://example.test/notes/'));
        $this->buildSite($root);

        $locs = $this->locs($root);
        foreach ($locs as $loc) {
            $this->assertStringStartsWith('https://example.test/notes/', $loc);
        }
        $this->assertContains('https://example.test/notes/', $locs);
        $this->assertContains('https://example.test/notes/essays/trace/', $locs);
        // the route is encoded once, segment by segment: no bare ampersand is left to escape
        $this->assertContains('https://example.test/notes/Caf%C3%A9%20%26%20Tea/', $locs);
        $this->assertStringContainsString('<loc>https://example.test/notes/Caf%C3%A9%20%26%20Tea/</loc>', $this->read($root, 'sitemap.xml'));
        $this->assertFileExists($root.'/dist/sitemap.xml');
        $this->assertFileDoesNotExist($root.'/dist/robots.txt');
        $this->assertFileDoesNotExist($root.'/dist/notes');
    }

    #[Test]
    public function drafts_scheduled_external_and_foreign_canonical_pages_are_left_out(): void
    {
        $root = $this->site([
            'content/elsewhere.md' => self::markdown('Elsewhere', ['canonical' => 'https://elsewhere.example/x/']),
            'content/mirror.md' => self::markdown('Mirror', ['canonical' => 'HTTP://Example.TEST:8080/about/']),
            'content/outside.md' => self::markdown('Outside', ['canonical' => 'https://example.test/outside/']),
        ], self::yaml('https://example.test/notes/'));
        $this->buildSite($root);

        $sitemap = $this->read($root, 'sitemap.xml');
        $locs = $this->locs($root);

        $this->assertNotContains('https://example.test/notes/elsewhere/', $locs);
        $this->assertFileExists($root.'/dist/elsewhere/index.html');
        // a same-host canonical keeps the page in, under its own URL
        $this->assertContains('https://example.test/notes/mirror/', $locs);
        $this->assertContains('https://example.test/notes/outside/', $locs);
        $this->assertNotContains('https://example.test/outside/', $locs);

        foreach (['draft-notes', 'future-article', 'published-elsewhere', 'blog.example.test', '404', 'search.json', 'elsewhere.example'] as $absent) {
            $this->assertStringNotContainsString($absent, $sitemap);
        }
        // the tags that only drafts and scheduled articles carry have no archive
        $this->assertNotContains('https://example.test/notes/tags/secret/', $locs);
        $this->assertNotContains('https://example.test/notes/tags/soon/', $locs);
    }

    #[Test]
    public function an_article_dated_exactly_now_is_listed_and_one_a_second_later_is_not(): void
    {
        $root = $this->site([
            'content/articles/on-the-dot.md' => self::markdown('On the dot', ['date' => '2026-10-20T12:00:00Z']),
            'content/articles/a-second-late.md' => self::markdown('A second late', ['date' => '2026-10-20T12:00:01Z']),
        ]);
        $this->buildSite($root, self::NOW);

        $locs = $this->locs($root);
        $this->assertContains('https://example.test/articles/on-the-dot/', $locs);
        $this->assertNotContains('https://example.test/articles/a-second-late/', $locs);
    }

    #[Test]
    public function lastmod_is_the_date_in_the_site_timezone_and_only_pages_have_one(): void
    {
        $root = $this->site([
            'content/dated.md' => self::markdown('Dated', ['date' => '2026-03-04']),
            'content/articles/late-night.md' => self::markdown('Late night', ['date' => '2026-10-09T23:30:00Z']),
        ], self::yaml(extra: "timezone: Europe/Istanbul\n"));
        $this->buildSite($root);

        $entries = $this->entries($root);
        // `updated` (2026-10-12) is never used
        $this->assertSame('2026-10-10T00:00:00+03:00', $entries['https://example.test/essays/trace/']);
        $this->assertSame('2026-03-04T00:00:00+03:00', $entries['https://example.test/dated/']);
        $this->assertSame('2026-10-10T02:30:00+03:00', $entries['https://example.test/articles/late-night/']);
        $this->assertSame('2025-06-01T00:00:00+03:00', $entries['https://example.test/articles/older-note/']);
        foreach (['', 'about/', 'projects/', 'legal/privacy/', 'articles/', 'tags/', 'tags/laravel/'] as $route) {
            $this->assertNull($entries['https://example.test/'.$route], $route);
        }
    }

    #[Test]
    public function the_trace_article_gets_its_date_as_lastmod_in_utc(): void
    {
        $root = $this->site();
        $this->buildSite($root);

        $this->assertSame('2026-10-10T00:00:00+00:00', $this->entries($root)['https://example.test/essays/trace/']);
    }

    #[Test]
    public function every_archive_and_tag_page_is_listed_and_external_articles_still_count(): void
    {
        $files = [
            'content/articles/ext-only.md' => self::markdown('Only external', [
                'date' => '2026-05-01',
                'tags' => '[Remote]',
                'external_url' => 'https://blog.example.test/remote',
            ]),
            'content/articles/ext-bulk.md' => self::markdown('Bulk external', [
                'date' => '2026-05-02',
                'tags' => '[Bulk]',
                'external_url' => 'https://blog.example.test/bulk',
            ]),
        ];
        for ($i = 1; $i <= 10; $i++) {
            $files[sprintf('content/articles/bulk-%02d.md', $i)] = self::markdown('Bulk '.$i, ['date' => sprintf('2026-01-%02d', $i), 'tags' => '[Bulk]']);
        }
        $root = $this->site($files);
        $this->buildSite($root);

        $locs = $this->locs($root);
        $this->assertContains('https://example.test/articles/page/2/', $locs);
        $this->assertContains('https://example.test/tags/bulk/', $locs);
        $this->assertContains('https://example.test/tags/bulk/page/2/', $locs);
        // a tag made only of external articles still has a local archive
        $this->assertContains('https://example.test/tags/remote/', $locs);
        $this->assertNotContains('https://example.test/articles/ext-only/', $locs);
        $this->assertNotContains('https://example.test/articles/ext-bulk/', $locs);
    }

    #[Test]
    public function a_site_without_articles_lists_the_home_page_pages_and_empty_archives(): void
    {
        $root = $this->site([
            'content/articles/draft-notes.md' => null,
            'content/articles/future-article.md' => null,
            'content/articles/older-note.md' => null,
            'content/articles/published-elsewhere.md' => null,
            'content/articles/trace' => null,
            'content/about.md' => self::markdown('About', ['menu' => 'true']),
        ]);
        $this->buildSite($root);

        $this->assertSame([
            'https://example.test/',
            'https://example.test/about/',
            'https://example.test/legal/privacy/',
            'https://example.test/projects/',
            'https://example.test/articles/',
            'https://example.test/tags/',
        ], $this->locs($root));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function collisions(): array
    {
        return [
            'sitemap.xml' => ['public/sitemap.xml', 'the sitemap'],
            'a case variant' => ['public/Sitemap.xml', 'the sitemap'],
            'a directory in the way' => ['public/sitemap.xml/child.txt', 'the sitemap'],
        ];
    }

    #[Test]
    #[DataProvider('collisions')]
    public function a_public_file_with_the_sitemap_name_is_an_error_and_keeps_the_previous_output(string $path, string $owner): void
    {
        $root = $this->site();
        $this->buildSite($root);
        $before = self::snapshot($root.'/dist');

        $this->writeFiles($root, [$path => "mine\n"]);

        try {
            $this->buildSite($root);
            $this->fail('Expected a ContentException.');
        } catch (ContentException $exception) {
            $this->assertStringContainsString($owner, $exception->getMessage());
            $this->assertStringContainsString('public file '.$path, $exception->getMessage());
        }
        $this->assertSame($before, self::snapshot($root.'/dist'));
    }

    #[Test]
    public function with_sitemap_false_a_public_sitemap_is_copied_unchanged(): void
    {
        $root = $this->site(['public/sitemap.xml' => "<urlset/>\n"], self::yaml(sitemap: false));
        $this->buildSite($root);

        $this->assertSame("<urlset/>\n", $this->read($root, 'sitemap.xml'));
    }

    #[Test]
    #[DataProvider('sitemapSettings')]
    public function a_public_robots_txt_is_always_copied_unchanged(bool $sitemap): void
    {
        $root = $this->site(['public/robots.txt' => "User-agent: *\nDisallow: /private/\n"], self::yaml(sitemap: $sitemap));
        $this->buildSite($root);

        $this->assertSame("User-agent: *\nDisallow: /private/\n", $this->read($root, 'robots.txt'));
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function sitemapSettings(): array
    {
        return ['sitemap true' => [true], 'sitemap false' => [false]];
    }

    #[Test]
    public function turning_the_option_off_removes_the_sitemap_on_the_next_build(): void
    {
        $root = $this->site();
        $this->buildSite($root);
        $this->assertFileExists($root.'/dist/sitemap.xml');

        $this->writeFiles($root, ['verse.yaml' => self::yaml(sitemap: false)]);
        $this->buildSite($root);

        $this->assertFileDoesNotExist($root.'/dist/sitemap.xml');
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function previews(): array
    {
        return [
            'drafts, with a url' => [true, true],
            'no drafts, with a url' => [false, true],
            'drafts, without a url' => [true, false],
            'no drafts, without a url' => [false, false],
        ];
    }

    #[Test]
    #[DataProvider('previews')]
    public function a_preview_never_writes_or_claims_the_sitemap(bool $drafts, bool $withUrl): void
    {
        $yaml = $withUrl ? "title: Notes\nurl: https://example.test/\n" : "title: Notes\n";
        // a `public/` file with a generated name must not collide in a preview either
        $root = $this->site(['public/sitemap.xml' => "<urlset/>\n"], $yaml);
        $output = $root.'/.verse/preview/'.($drafts ? 'drafts' : 'public');

        $themes = ThemeRegistry::bundled();
        $config = (new ConfigLoader($themes))->load($root, requireUrl: false);
        $context = BuildContext::create(
            $root,
            $config,
            BuildMode::Preview,
            $drafts,
            new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')),
            $output,
        );
        (new Builder($themes, new LocalFilesystem))->build($context);

        $this->assertFileExists($output.'/index.html');
        $this->assertSame("<urlset/>\n", file_get_contents($output.'/sitemap.xml'));
    }

    #[Test]
    public function a_preview_without_public_files_has_no_sitemap(): void
    {
        $root = $this->site();
        $this->buildSite($root, drafts: true);

        $this->assertFileExists($root.'/.verse/preview/drafts/index.html');
        $this->assertFileDoesNotExist($root.'/.verse/preview/drafts/sitemap.xml');
    }

    private function check(string $root, ?string $tempRoot = null): void
    {
        $themes = ThemeRegistry::bundled();
        $builder = new Builder($themes, new LocalFilesystem, tempRoot: $tempRoot ?? $this->makeSite());
        $builder->checkSite(static fn (): BuildContext => BuildContext::create(
            $root,
            (new ConfigLoader($themes))->load($root, requireUrl: true),
            now: new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')),
        ));
    }

    #[Test]
    public function check_passes_with_the_option_and_changes_nothing(): void
    {
        $root = $this->site();
        $before = self::snapshot($root);

        $this->check($root);

        $this->assertSame($before, self::snapshot($root));
        $this->assertDirectoryDoesNotExist($root.'/dist');
        $this->assertDirectoryDoesNotExist($root.'/.verse');
    }

    #[Test]
    public function check_reports_a_collision_naming_both_owners(): void
    {
        $root = $this->site(['public/sitemap.xml' => "mine\n"]);

        try {
            $this->check($root);
            $this->fail('Expected a ContentException.');
        } catch (ContentException $exception) {
            $this->assertStringContainsString('the sitemap', $exception->getMessage());
            $this->assertStringContainsString('public file public/sitemap.xml', $exception->getMessage());
        }
    }
}
