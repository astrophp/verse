<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Build;

use Astro\Verse\Error\ContentException;
use Astro\Verse\Tests\Support\Html;
use Astro\Verse\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The static search index (`search.json`) and the header's search button.
 */
final class SearchIndexTest extends TestCase
{
    private const string TRACE = '{"title":"The bug hiding in a successful trace","description":"Fixture article. A successful run can still be wrong, and a trace shows why.","tags":["AI systems","Observability"],"date":"2026-10-10","dateLabel":"10 Oct 2026","url":"/essays/trace/","external":false}';

    private const string OLDER = '{"title":"An older fixture note","description":"A short older note, so the timeline has two year groups.","tags":["Laravel"],"date":"2025-06-01","dateLabel":"1 Jun 2025","url":"/articles/older-note/","external":false}';

    private const string EXTERNAL = '{"title":"A fixture article published elsewhere","description":"Fixture entry for an article that doesn’t live on this site.","tags":["AI systems","Laravel","Observability"],"date":"2026-10-15","dateLabel":"15 Oct 2026","url":"https://blog.example.test/a-fixture-article-published-elsewhere","external":true}';

    private function index(string $root): string
    {
        $index = file_get_contents($root.'/dist/search.json');
        $this->assertIsString($index);

        return $index;
    }

    #[Test]
    public function the_index_lists_published_articles_newest_first_with_the_documented_fields(): void
    {
        $root = $this->fixtureSite();
        $this->buildSite($root);

        // the draft and the scheduled item are filtered out; pages (about, projects, privacy) are not indexed
        $this->assertSame('['.self::EXTERNAL.','.self::TRACE.','.self::OLDER.']', $this->index($root));
    }

    #[Test]
    public function the_index_is_sorted_by_date_with_an_external_entry_and_nullable_descriptions(): void
    {
        $root = $this->fixtureSite('notes', [
            'content/articles/between.md' => "---\ntitle: Between\ndate: 2026-10-12\n---\nText.\n",
            'content/articles/elsewhere.md' => "---\ntitle: Elsewhere\ndescription: '   '\ndate: 2026-10-20\nexternal_url: https://example.org/a?x=1&y=2\n---\n",
        ]);
        $this->buildSite($root);

        $entries = json_decode($this->index($root), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($entries);
        $this->assertSame(
            ['Elsewhere', 'A fixture article published elsewhere', 'Between', 'The bug hiding in a successful trace', 'An older fixture note'],
            array_column($entries, 'title'),
        );
        $this->assertSame(
            ['title' => 'Between', 'description' => null, 'tags' => [], 'date' => '2026-10-12', 'dateLabel' => '12 Oct 2026', 'url' => '/articles/between/', 'external' => false],
            $entries[2],
        );
        $this->assertNull($entries[0]['description']);
        $this->assertTrue($entries[0]['external']);
        $this->assertSame('https://example.org/a?x=1&y=2', $entries[0]['url']);
    }

    #[Test]
    public function local_urls_carry_the_base_path_and_external_urls_do_not(): void
    {
        $yaml = (string) preg_replace('~^url: .*$~m', 'url: https://example.test/notes/', (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sites/notes/verse.yaml'));
        $root = $this->fixtureSite('notes', ['verse.yaml' => $yaml]);
        $this->buildSite($root);

        $entries = json_decode($this->index($root), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($entries);
        $this->assertSame(
            ['https://blog.example.test/a-fixture-article-published-elsewhere', '/notes/essays/trace/', '/notes/articles/older-note/'],
            array_column($entries, 'url'),
        );
    }

    #[Test]
    public function dates_are_in_the_site_timezone(): void
    {
        $yaml = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sites/notes/verse.yaml');
        $root = $this->fixtureSite('notes', [
            'verse.yaml' => $yaml."timezone: Europe/Istanbul\n",
            'content/articles/ny.md' => "---\ntitle: New year\ndate: 2026-12-31T22:30:00Z\n---\nText.\n",
        ]);
        $this->buildSite($root, '2027-02-01T00:00:00Z');

        $entries = json_decode($this->index($root), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($entries);
        $this->assertSame('New year', $entries[0]['title']);
        $this->assertSame('2027-01-01', $entries[0]['date']);
    }

    #[Test]
    public function a_drafts_preview_includes_drafts_and_scheduled_items_and_production_does_not(): void
    {
        $root = $this->fixtureSite();
        $this->buildSite($root, drafts: true);

        $entries = json_decode((string) file_get_contents($root.'/.verse/preview/drafts/search.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($entries);
        $this->assertSame(
            ['Scheduled for later', 'A fixture article published elsewhere', 'The bug hiding in a successful trace', 'Draft notes', 'An older fixture note'],
            array_column($entries, 'title'),
        );
        $this->assertSame(
            ['scheduled', null, null, 'draft', null],
            array_map(static fn (array $entry): ?string => $entry['state'], $entries),
        );
        // the drafts index keeps the production fields and only adds `state` at the end
        $this->assertSame(['title', 'description', 'tags', 'date', 'dateLabel', 'url', 'external', 'state'], array_keys($entries[1]));

        $production = $this->fixtureSite();
        $this->buildSite($production);
        $this->assertStringNotContainsString('Draft notes', $this->index($production));
        $this->assertStringNotContainsString('Scheduled for later', $this->index($production));
        $this->assertStringNotContainsString('"state"', $this->index($production));
    }

    #[Test]
    public function a_site_without_articles_still_gets_an_empty_index(): void
    {
        $root = $this->makeSite([
            'verse.yaml' => self::SITE_YAML,
            'content/about.md' => self::markdown('About', ['menu' => 'true']),
        ]);
        $this->buildSite($root);

        $this->assertSame('[]', $this->index($root));
    }

    #[Test]
    public function the_encoding_is_compact_unescaped_and_byte_identical_between_builds(): void
    {
        $first = $this->fixtureSite();
        $second = $this->fixtureSite();
        $this->buildSite($first);
        $this->buildSite($second);

        $index = $this->index($first);
        $this->assertSame($index, $this->index($second));
        $this->assertStringNotContainsString("\n", $index);
        $this->assertStringContainsString('doesn’t', $index);
        $this->assertStringContainsString('"url":"/essays/trace/"', $index);
        $this->assertStringNotContainsString('\/', $index);
        $this->assertStringNotContainsString('\u', $index);
    }

    #[Test]
    public function a_public_file_named_search_json_collides_naming_both_owners(): void
    {
        try {
            $this->loadSite(['public/search.json' => '[]']);
        } catch (ContentException $exception) {
            $this->assertStringContainsString('"search.json"', $exception->getMessage());
            $this->assertStringContainsString('the search index', $exception->getMessage());
            $this->assertStringContainsString('public file public/search.json', $exception->getMessage());

            return;
        }
        $this->fail('Expected a ContentException');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function basePaths(): array
    {
        return [
            'root' => ['https://example.test/', '/'],
            'subdirectory' => ['https://example.test/notes/', '/notes/'],
        ];
    }

    #[Test]
    #[DataProvider('basePaths')]
    public function the_header_renders_a_hidden_search_button_before_the_theme_menu(string $url, string $base): void
    {
        $yaml = (string) preg_replace('~^url: .*$~m', 'url: '.$url, (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sites/notes/verse.yaml'));
        $root = $this->fixtureSite('notes', ['verse.yaml' => $yaml]);
        $this->buildSite($root);

        foreach (['index.html', 'essays/trace/index.html', '404.html'] as $path) {
            $html = (string) file_get_contents($root.'/dist/'.$path);
            $xpath = Html::parse($html);

            $this->assertSame(1, $xpath->count('//nav[@class="header-nav"]/button[@class="search-button"][@hidden][@data-open-search]'), $path);
            $this->assertSame($base.'search.json', $xpath->attr('//button[@data-open-search]', 'data-search-index'), $path);
            $this->assertSame('Search', $xpath->attr('//button[@data-open-search]', 'aria-label'), $path);
            $this->assertSame(1, $xpath->count('//button[@data-open-search]/svg[@class="icon"]/circle'), $path);
            $this->assertSame(1, $xpath->count('//nav[@class="header-nav"]/button[@data-open-search]/following-sibling::div[@class="theme-menu"]'), $path);
            // no dialog without JavaScript
            $this->assertSame(0, $xpath->count('//dialog'), $path);

            // the assets stay one stylesheet and one module script
            $this->assertSame(1, substr_count($html, '<link rel="stylesheet"'), $path);
            $this->assertSame(1, substr_count($html, '<script type="module"'), $path);
        }
    }
}
