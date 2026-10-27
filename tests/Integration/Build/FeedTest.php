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
 * `feed.xml`: what it contains, when it is written and what it collides with. It is a summary-only Atom feed.
 */
final class FeedTest extends TestCase
{
    private const string NAMESPACE = 'http://www.w3.org/2005/Atom';

    private const string NOW = '2026-10-20T12:00:00Z';

    private const string DISCOVERY = '<link rel="alternate" type="application/atom+xml"';

    private static function yaml(string $url = 'https://example.test/', string $extra = '', bool $feed = true): string
    {
        return "title: Notes\nurl: ".$url."\n".'feed: '.($feed ? 'true' : 'false')."\n".$extra;
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

    /** @var array<string, DOMXPath> */
    private array $xpaths = [];

    /**
     * The parsed `dist/feed.xml`; one parse per distinct content, so elements stay usable with their XPath.
     */
    private function xpath(string $root): DOMXPath
    {
        $contents = $this->read($root, 'feed.xml');
        $key = $root.'|'.md5($contents);
        if (! isset($this->xpaths[$key])) {
            $document = new DOMDocument;
            $this->assertTrue($document->loadXML($contents));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('a', self::NAMESPACE);
            $this->xpaths[$key] = $xpath;
        }

        return $this->xpaths[$key];
    }

    private function text(DOMXPath $xpath, string $expression, ?\DOMNode $context = null): string
    {
        $value = $xpath->evaluate('string('.$expression.')', $context);
        $this->assertIsString($value);

        return $value;
    }

    private function nodes(DOMXPath $xpath, string $expression, ?\DOMNode $context = null): int
    {
        $value = $xpath->evaluate('count('.$expression.')', $context);
        $this->assertIsFloat($value);

        return (int) $value;
    }

    /**
     * @return list<string> the `id` of every entry in order
     */
    private function ids(string $root): array
    {
        $xpath = $this->xpath($root);

        $ids = [];
        foreach ($xpath->query('/a:feed/a:entry') ?: [] as $entry) {
            $this->assertInstanceOf(DOMElement::class, $entry);
            $ids[] = $this->text($xpath, 'a:id', $entry);
        }

        return $ids;
    }

    private function entry(string $root, string $id): DOMElement
    {
        $xpath = $this->xpath($root);
        $entries = $xpath->query('/a:feed/a:entry[a:id="'.$id.'"]');
        $this->assertNotFalse($entries);
        $this->assertSame(1, $entries->length, $id);
        $entry = $entries->item(0);
        $this->assertInstanceOf(DOMElement::class, $entry);

        return $entry;
    }

    #[Test]
    public function the_feed_is_written_by_default(): void
    {
        $root = $this->site([], "title: Notes\nurl: https://example.test/\n");
        $this->buildSite($root);

        $this->assertFileExists($root.'/dist/feed.xml');
        $this->assertFileDoesNotExist($root.'/dist/robots.txt');
    }

    #[Test]
    public function the_feed_level_elements_describe_the_site(): void
    {
        $root = $this->site();
        $this->buildSite($root);

        $xml = $this->read($root, 'feed.xml');
        $this->assertStringStartsWith("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<feed xmlns=\"".self::NAMESPACE."\" xml:lang=\"en\">\n  <title>Notes</title>\n", $xml);
        $this->assertStringEndsWith("  </entry>\n</feed>\n", $xml);

        $xpath = $this->xpath($root);
        $this->assertSame('Notes', $this->text($xpath, '/a:feed/a:title'));
        $this->assertSame(
            'https://example.test/',
            $this->text($xpath, '/a:feed/a:link[@rel="alternate"]/@href'),
        );
        $this->assertSame('text/html', $this->text($xpath, '/a:feed/a:link[@rel="alternate"]/@type'));
        $this->assertSame(
            'https://example.test/feed.xml',
            $this->text($xpath, '/a:feed/a:link[@rel="self"]/@href'),
        );
        $this->assertSame('application/atom+xml', $this->text($xpath, '/a:feed/a:link[@rel="self"]/@type'));
        $this->assertSame('https://example.test/', $this->text($xpath, '/a:feed/a:id'));
        $this->assertSame('Verse', $this->text($xpath, '/a:feed/a:generator'));
        $this->assertSame('https://github.com/astrophp/verse', $this->text($xpath, '/a:feed/a:generator/@uri'));
        $this->assertNotSame('', $this->text($xpath, '/a:feed/a:generator/@version'));
        $this->assertSame('en', $this->text($xpath, '/a:feed/@xml:lang'));
    }

    #[Test]
    public function the_language_is_the_site_language(): void
    {
        $root = $this->site([], self::yaml(extra: "language: de\n"));
        $this->buildSite($root);

        $this->assertSame('de', $this->text($this->xpath($root), '/a:feed/@xml:lang'));
    }

    #[Test]
    public function the_subtitle_is_the_description_and_absent_when_it_is_blank(): void
    {
        $root = $this->site([], self::yaml(extra: "description: Notes on things & stuff.\n"));
        $this->buildSite($root);
        $this->assertSame('Notes on things & stuff.', $this->text($this->xpath($root), '/a:feed/a:subtitle'));

        $blank = $this->site([], self::yaml(extra: "description: '   '\n"));
        $this->buildSite($blank);
        $this->assertSame(0, $this->nodes($this->xpath($blank), '/a:feed/a:subtitle'));

        $none = $this->site([], self::yaml());
        $this->buildSite($none);
        $this->assertSame(0, $this->nodes($this->xpath($none), '/a:feed/a:subtitle'));
    }

    #[Test]
    public function the_author_is_the_configured_name_with_its_url_and_email_when_set(): void
    {
        $root = $this->site([], self::yaml(extra: "author:\n  name: Jane Doe\n  url: https://jane.example.net/\n  email: jane@example.net\n"));
        $this->buildSite($root);

        $xpath = $this->xpath($root);
        $this->assertSame('Jane Doe', $this->text($xpath, '/a:feed/a:author/a:name'));
        $this->assertSame('https://jane.example.net/', $this->text($xpath, '/a:feed/a:author/a:uri'));
        $this->assertSame('jane@example.net', $this->text($xpath, '/a:feed/a:author/a:email'));
    }

    #[Test]
    public function an_author_without_url_and_email_has_only_a_name(): void
    {
        $root = $this->site([], self::yaml(extra: "author: Jane Doe\n"));
        $this->buildSite($root);

        $xpath = $this->xpath($root);
        $this->assertSame('Jane Doe', $this->text($xpath, '/a:feed/a:author/a:name'));
        $this->assertSame(0, $this->nodes($xpath, '/a:feed/a:author/a:uri'));
        $this->assertSame(0, $this->nodes($xpath, '/a:feed/a:author/a:email'));
    }

    #[Test]
    public function without_an_author_the_site_title_is_the_author(): void
    {
        // the notes fixture sets no author in this verse.yaml (only the title and url)
        $root = $this->site();
        $this->buildSite($root);

        $xpath = $this->xpath($root);
        $this->assertSame('Notes', $this->text($xpath, '/a:feed/a:author/a:name'));
        $this->assertSame(0, $this->nodes($xpath, '/a:feed/a:author/a:uri'));
        $this->assertSame(0, $this->nodes($xpath, '/a:feed/a:author/a:email'));
    }

    #[Test]
    public function entries_are_the_eligible_articles_newest_first_and_drafts_and_scheduled_are_absent(): void
    {
        $root = $this->site();
        $this->buildSite($root);

        $this->assertSame([
            'https://blog.example.test/a-fixture-article-published-elsewhere',
            'https://example.test/essays/trace/',
            'https://example.test/articles/older-note/',
        ], $this->ids($root));
        $feed = $this->read($root, 'feed.xml');
        foreach (['draft-notes', 'future-article', 'Draft notes', 'Scheduled for later', 'Secret', 'Soon'] as $absent) {
            $this->assertStringNotContainsString($absent, $feed);
        }
    }

    #[Test]
    public function an_entry_has_a_title_link_id_dates_summary_and_categories_but_no_content(): void
    {
        $root = $this->site();
        $this->buildSite($root);

        $xpath = $this->xpath($root);
        $entry = $this->entry($root, 'https://example.test/essays/trace/');
        $this->assertSame('The bug hiding in a successful trace', $this->text($xpath, 'a:title', $entry));
        $this->assertSame('text', $this->text($xpath, 'a:title/@type', $entry));
        $this->assertSame('https://example.test/essays/trace/', $this->text($xpath, 'a:link[@rel="alternate"]/@href', $entry));
        $this->assertSame('text/html', $this->text($xpath, 'a:link[@rel="alternate"]/@type', $entry));
        $this->assertSame('2026-10-10T00:00:00+00:00', $this->text($xpath, 'a:published', $entry));
        $this->assertSame('2026-10-12T00:00:00+00:00', $this->text($xpath, 'a:updated', $entry));
        $this->assertSame(
            'Fixture article. A successful run can still be wrong, and a trace shows why.',
            $this->text($xpath, 'a:summary', $entry),
        );
        $this->assertSame('text', $this->text($xpath, 'a:summary/@type', $entry));
        $this->assertSame(0, $this->nodes($xpath, 'a:content', $entry));

        $terms = [];
        foreach ($xpath->query('a:category/@term', $entry) ?: [] as $term) {
            $terms[] = $term->nodeValue;
        }
        $this->assertSame(['AI systems', 'Observability'], $terms);
        $this->assertStringNotContainsString('A successful run can still be wrong</', $this->read($root, 'feed.xml'));
        $this->assertStringNotContainsString('Fixture text', $this->read($root, 'feed.xml'));
    }

    #[Test]
    public function dates_are_in_the_site_timezone_and_updated_falls_back_to_the_date(): void
    {
        $root = $this->site([
            'content/articles/late-night.md' => self::markdown('Late night', ['date' => '2026-10-09T23:30:00Z']),
        ], self::yaml(extra: "timezone: Europe/Istanbul\n"));
        $this->buildSite($root);

        $xpath = $this->xpath($root);
        $trace = $this->entry($root, 'https://example.test/essays/trace/');
        $this->assertSame('2026-10-10T00:00:00+03:00', $this->text($xpath, 'a:published', $trace));
        $this->assertSame('2026-10-12T00:00:00+03:00', $this->text($xpath, 'a:updated', $trace));

        $late = $this->entry($root, 'https://example.test/articles/late-night/');
        $this->assertSame('2026-10-10T02:30:00+03:00', $this->text($xpath, 'a:published', $late));
        $this->assertSame('2026-10-10T02:30:00+03:00', $this->text($xpath, 'a:updated', $late));
    }

    #[Test]
    public function an_entry_without_a_description_has_no_summary(): void
    {
        $root = $this->site([
            'content/articles/plain.md' => self::markdown('Plain', ['date' => '2026-10-16', 'description' => "'   '"]),
            'content/articles/untagged.md' => self::markdown('Untagged', ['date' => '2026-10-17']),
        ]);
        $this->buildSite($root);

        $xpath = $this->xpath($root);
        foreach (['plain', 'untagged'] as $slug) {
            $entry = $this->entry($root, 'https://example.test/articles/'.$slug.'/');
            $this->assertSame(0, $this->nodes($xpath, 'a:summary', $entry), $slug);
            $this->assertSame(0, $this->nodes($xpath, 'a:category', $entry), $slug);
        }
    }

    #[Test]
    public function the_feed_is_updated_at_the_latest_entry_update_not_the_newest_article(): void
    {
        $root = $this->site([
            'content/articles/older-note.md' => self::markdown('An older note', ['date' => '2025-06-01', 'updated' => '2026-10-19']),
        ]);
        $this->buildSite($root);

        // the newest article is from 2026-10-15; an older one was updated later
        $this->assertSame('2026-10-19T00:00:00+00:00', $this->text($this->xpath($root), '/a:feed/a:updated'));
    }

    #[Test]
    public function the_feed_update_compares_instants_not_formatted_strings(): void
    {
        // Berlin leaves summer time at 03:00 on 2026-10-25: 02:15+01:00 (01:15Z) is later than 02:45+02:00 (00:45Z),
        // although it sorts earlier as text
        $root = $this->site([
            'content/articles/a.md' => self::markdown('A', ['date' => '2026-10-25T02:15:00+01:00']),
            'content/articles/b.md' => self::markdown('B', ['date' => '2026-10-25T02:45:00+02:00']),
        ], self::yaml(extra: "timezone: Europe/Berlin\n"));
        $this->buildSite($root, '2026-10-26T12:00:00Z');

        $this->assertSame('2026-10-25T02:15:00+01:00', $this->text($this->xpath($root), '/a:feed/a:updated'));
    }

    #[Test]
    public function an_external_article_links_and_identifies_by_its_external_url(): void
    {
        $root = $this->site();
        $this->buildSite($root);

        $xpath = $this->xpath($root);
        $entry = $this->entry($root, 'https://blog.example.test/a-fixture-article-published-elsewhere');
        $this->assertSame(
            'https://blog.example.test/a-fixture-article-published-elsewhere',
            $this->text($xpath, 'a:link[@rel="alternate"]/@href', $entry),
        );
        $this->assertSame('A fixture article published elsewhere', $this->text($xpath, 'a:title', $entry));
        $this->assertSame('2026-10-15T00:00:00+00:00', $this->text($xpath, 'a:published', $entry));
    }

    #[Test]
    public function an_article_with_a_canonical_elsewhere_still_links_to_its_own_page(): void
    {
        $root = $this->site([
            'content/articles/mirrored.md' => self::markdown('Mirrored', ['date' => '2026-10-16', 'canonical' => 'https://elsewhere.example/mirrored/']),
        ]);
        $this->buildSite($root);

        $entry = $this->entry($root, 'https://example.test/articles/mirrored/');
        $this->assertSame('https://example.test/articles/mirrored/', $this->text($this->xpath($root), 'a:link[@rel="alternate"]/@href', $entry));
        $this->assertStringNotContainsString('elsewhere.example', $this->read($root, 'feed.xml'));
    }

    #[Test]
    public function under_a_base_path_every_url_carries_it_and_the_discovery_link_points_to_the_feed(): void
    {
        $root = $this->site([], self::yaml('https://example.test/blog/'));
        $this->buildSite($root);

        $xpath = $this->xpath($root);
        $this->assertSame('https://example.test/blog/', $this->text($xpath, '/a:feed/a:id'));
        $this->assertSame('https://example.test/blog/', $this->text($xpath, '/a:feed/a:link[@rel="alternate"]/@href'));
        $this->assertSame('https://example.test/blog/feed.xml', $this->text($xpath, '/a:feed/a:link[@rel="self"]/@href'));
        $this->assertSame([
            'https://blog.example.test/a-fixture-article-published-elsewhere',
            'https://example.test/blog/essays/trace/',
            'https://example.test/blog/articles/older-note/',
        ], $this->ids($root));
        $this->assertFileExists($root.'/dist/feed.xml');
        $this->assertStringContainsString(
            self::DISCOVERY.' title="Notes" href="/blog/feed.xml">',
            $this->read($root, 'index.html'),
        );
    }

    /**
     * @return array<string, ?string>
     */
    private static function articles(int $count): array
    {
        $files = [];
        for ($i = 1; $i <= $count; $i++) {
            $files[sprintf('content/articles/bulk-%02d.md', $i)] = self::markdown('Bulk '.$i, ['date' => sprintf('2026-02-%02d', $i)]);
        }

        return $files;
    }

    #[Test]
    public function only_the_twenty_newest_articles_are_listed(): void
    {
        // the notes fixture has three eligible articles; 18 more make 21
        $root = $this->site(self::articles(18));
        $this->buildSite($root);

        $ids = $this->ids($root);
        $this->assertCount(20, $ids);
        $this->assertSame('https://blog.example.test/a-fixture-article-published-elsewhere', $ids[0]);
        $this->assertSame('https://example.test/essays/trace/', $ids[1]);
        // the oldest of the 21, the 2025 fixture note, is cut off
        $this->assertNotContains('https://example.test/articles/older-note/', $ids);
        $this->assertContains('https://example.test/articles/bulk-01/', $ids);
    }

    #[Test]
    public function exactly_twenty_articles_are_all_listed(): void
    {
        $root = $this->site(self::articles(17));
        $this->buildSite($root);

        $this->assertCount(20, $this->ids($root));
        $this->assertContains('https://example.test/articles/older-note/', $this->ids($root));
    }

    #[Test]
    public function a_site_without_articles_has_a_valid_feed_without_entries_updated_now(): void
    {
        $root = $this->site([
            'content/articles/draft-notes.md' => null,
            'content/articles/future-article.md' => null,
            'content/articles/older-note.md' => null,
            'content/articles/published-elsewhere.md' => null,
            'content/articles/trace' => null,
            'content/about.md' => self::markdown('About', ['menu' => 'true']),
        ]);
        $this->buildSite($root, self::NOW);

        $xpath = $this->xpath($root);
        $this->assertSame(0, $this->nodes($xpath, '/a:feed/a:entry'));
        $this->assertSame('2026-10-20T12:00:00+00:00', $this->text($xpath, '/a:feed/a:updated'));
        $this->assertSame('Notes', $this->text($xpath, '/a:feed/a:title'));
    }

    #[Test]
    public function the_empty_feed_is_updated_now_in_the_site_timezone(): void
    {
        $root = $this->site([
            'content/articles/draft-notes.md' => null,
            'content/articles/future-article.md' => null,
            'content/articles/older-note.md' => null,
            'content/articles/published-elsewhere.md' => null,
            'content/articles/trace' => null,
            'content/about.md' => self::markdown('About', ['menu' => 'true']),
        ], self::yaml(extra: "timezone: Europe/Istanbul\n"));
        $this->buildSite($root, self::NOW);

        $this->assertSame('2026-10-20T15:00:00+03:00', $this->text($this->xpath($root), '/a:feed/a:updated'));
    }

    #[Test]
    public function two_external_articles_with_the_same_url_are_an_error_naming_the_later_file(): void
    {
        $root = $this->site([
            'content/articles/ext-a.md' => self::markdown('A', ['date' => '2026-10-17', 'external_url' => 'https://blog.example.test/same']),
            'content/articles/ext-b.md' => self::markdown('B', ['date' => '2026-10-16', 'external_url' => 'https://blog.example.test/same']),
        ]);

        try {
            $this->buildSite($root);
            $this->fail('Expected a ContentException.');
        } catch (ContentException $exception) {
            $this->assertStringContainsString('content/articles/ext-b.md', $exception->getMessage());
            $this->assertStringContainsString('same feed id as content/articles/ext-a.md (https://blog.example.test/same)', $exception->getMessage());
            $this->assertStringContainsString('external_url', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($root.'/dist');
    }

    #[Test]
    public function external_urls_that_differ_only_by_characters_removed_from_the_feed_are_duplicates(): void
    {
        $root = $this->site([
            'content/articles/ext-a.md' => self::markdown('A', ['date' => '2026-10-17', 'external_url' => 'https://blog.example.test/same']),
            'content/articles/ext-b.md' => self::markdown('B', ['date' => '2026-10-16', 'external_url' => '"https://blog.example.test/same\\uFFFF"']),
        ]);

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('same feed id as content/articles/ext-a.md (https://blog.example.test/same)');
        $this->buildSite($root);
    }

    #[Test]
    public function an_external_url_equal_to_a_local_article_url_is_an_error(): void
    {
        $root = $this->site([
            'content/articles/echo.md' => self::markdown('Echo', ['date' => '2026-10-14', 'external_url' => 'https://example.test/essays/trace/']),
        ]);

        try {
            $this->buildSite($root);
            $this->fail('Expected a ContentException.');
        } catch (ContentException $exception) {
            $this->assertStringContainsString('content/articles/echo.md', $exception->getMessage());
            $this->assertStringContainsString('same feed id as', $exception->getMessage());
            $this->assertStringContainsString('(https://example.test/essays/trace/)', $exception->getMessage());
        }
    }

    #[Test]
    public function a_duplicate_beyond_the_twentieth_entry_is_not_in_the_feed_and_not_an_error(): void
    {
        $files = self::articles(20);
        $files['content/articles/old-a.md'] = self::markdown('Old A', ['date' => '2000-01-02', 'external_url' => 'https://blog.example.test/old']);
        $files['content/articles/old-b.md'] = self::markdown('Old B', ['date' => '2000-01-01', 'external_url' => 'https://blog.example.test/old']);
        $root = $this->site($files);
        $this->buildSite($root);

        $this->assertCount(20, $this->ids($root));
        $this->assertNotContains('https://blog.example.test/old', $this->ids($root));
    }

    #[Test]
    public function text_is_escaped_and_forbidden_control_characters_are_removed(): void
    {
        $root = $this->site([
            'content/articles/tricky.md' => "---\ntitle: \"Tom & Jerry <b> ]]> Ünï \\x01 end\"\ndescription: \"Tags <i>& \\x02 more\"\ndate: 2026-10-16\ntags: [\"A&B\", \"say \\\"hi\\\"\"]\n---\nBody.\n",
        ]);
        $this->buildSite($root);

        $xpath = $this->xpath($root);
        $entry = $this->entry($root, 'https://example.test/articles/tricky/');
        $this->assertSame('Tom & Jerry <b> ]]> Ünï  end', $this->text($xpath, 'a:title', $entry));
        $this->assertSame('Tags <i>&  more', $this->text($xpath, 'a:summary', $entry));
        $terms = [];
        foreach ($xpath->query('a:category/@term', $entry) ?: [] as $term) {
            $terms[] = $term->nodeValue;
        }
        $this->assertSame(['A&B', 'say "hi"'], $terms);
        $this->assertStringContainsString('<title type="text">Tom &amp; Jerry &lt;b&gt; ]]&gt; Ünï  end</title>', $this->read($root, 'feed.xml'));
        $this->assertStringNotContainsString("\x01", $this->read($root, 'feed.xml'));
    }

    #[Test]
    public function the_site_title_and_description_are_escaped_too(): void
    {
        $root = $this->site([], "title: 'Notes & <Co>'\nurl: https://example.test/\ndescription: 'a ]]> b'\n");
        $this->buildSite($root);

        $xpath = $this->xpath($root);
        $this->assertSame('Notes & <Co>', $this->text($xpath, '/a:feed/a:title'));
        $this->assertSame('a ]]> b', $this->text($xpath, '/a:feed/a:subtitle'));
        $this->assertSame('Notes & <Co>', $this->text($xpath, '/a:feed/a:author/a:name'));
    }

    #[Test]
    public function every_page_links_to_the_feed(): void
    {
        $root = $this->site();
        $this->buildSite($root);

        foreach (['index.html', 'about/index.html', 'articles/index.html', 'essays/trace/index.html', 'tags/index.html', '404.html'] as $page) {
            $this->assertStringContainsString(
                self::DISCOVERY.' title="Notes" href="/feed.xml">',
                $this->read($root, $page),
                $page,
            );
        }
    }

    #[Test]
    public function feed_false_writes_no_feed_and_no_discovery_link(): void
    {
        $root = $this->site([], self::yaml(feed: false));
        $this->buildSite($root);

        $this->assertFileDoesNotExist($root.'/dist/feed.xml');
        $this->assertFileExists($root.'/dist/index.html');
        $this->assertStringNotContainsString(self::DISCOVERY, $this->read($root, 'index.html'));
        $this->assertStringNotContainsString('feed.xml', $this->read($root, 'index.html'));
    }

    #[Test]
    public function turning_the_option_off_removes_the_feed_on_the_next_build(): void
    {
        $root = $this->site();
        $this->buildSite($root);
        $this->assertFileExists($root.'/dist/feed.xml');

        $this->writeFiles($root, ['verse.yaml' => self::yaml(feed: false)]);
        $this->buildSite($root);

        $this->assertFileDoesNotExist($root.'/dist/feed.xml');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function collisions(): array
    {
        return [
            'feed.xml' => ['public/feed.xml', 'the feed'],
            'a case variant' => ['public/Feed.xml', 'the feed'],
            'a directory in the way' => ['public/feed.xml/child.txt', 'the feed'],
        ];
    }

    #[Test]
    #[DataProvider('collisions')]
    public function a_public_file_with_the_feed_name_is_an_error_and_keeps_the_previous_output(string $path, string $owner): void
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
    public function a_page_with_the_permalink_feed_xml_is_an_error_and_keeps_the_previous_output(): void
    {
        $root = $this->site();
        $this->buildSite($root);
        $before = self::snapshot($root.'/dist');

        $this->writeFiles($root, ['content/articles/odd.md' => self::markdown('Odd', ['date' => '2026-10-16', 'permalink' => '/feed.xml/'])]);

        try {
            $this->buildSite($root);
            $this->fail('Expected a ContentException.');
        } catch (ContentException $exception) {
            $this->assertStringContainsString('the feed', $exception->getMessage());
            $this->assertStringContainsString('content/articles/odd.md', $exception->getMessage());
        }
        $this->assertSame($before, self::snapshot($root.'/dist'));
    }

    #[Test]
    public function with_feed_false_a_public_feed_is_copied_unchanged(): void
    {
        $root = $this->site(['public/feed.xml' => "<feed/>\n"], self::yaml(feed: false));
        $this->buildSite($root);

        $this->assertSame("<feed/>\n", $this->read($root, 'feed.xml'));
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
    public function a_preview_never_writes_or_claims_the_feed_and_has_no_discovery_link(bool $drafts, bool $withUrl): void
    {
        $yaml = $withUrl ? "title: Notes\nurl: https://example.test/\n" : "title: Notes\n";
        // a `public/` file with a generated name must not collide in a preview either
        $root = $this->site(['public/feed.xml' => "<feed/>\n"], $yaml);
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
        $this->assertSame("<feed/>\n", file_get_contents($output.'/feed.xml'));
        $index = file_get_contents($output.'/index.html');
        $this->assertIsString($index);
        $this->assertStringNotContainsString(self::DISCOVERY, $index);
    }

    #[Test]
    public function a_preview_without_public_files_has_no_feed(): void
    {
        $root = $this->site();
        $this->buildSite($root, drafts: true);

        $this->assertFileExists($root.'/.verse/preview/drafts/index.html');
        $this->assertFileDoesNotExist($root.'/.verse/preview/drafts/feed.xml');
    }

    #[Test]
    public function two_builds_produce_the_same_feed_bytes(): void
    {
        $root = $this->site();
        $this->buildSite($root);
        $first = $this->read($root, 'feed.xml');

        $this->buildSite($root);

        $this->assertSame($first, $this->read($root, 'feed.xml'));
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
        $root = $this->site(['public/feed.xml' => "mine\n"]);

        try {
            $this->check($root);
            $this->fail('Expected a ContentException.');
        } catch (ContentException $exception) {
            $this->assertStringContainsString('the feed', $exception->getMessage());
            $this->assertStringContainsString('public file public/feed.xml', $exception->getMessage());
        }
    }

    #[Test]
    public function check_reports_duplicate_feed_ids(): void
    {
        $root = $this->site([
            'content/articles/ext-a.md' => self::markdown('A', ['date' => '2026-10-17', 'external_url' => 'https://blog.example.test/same']),
            'content/articles/ext-b.md' => self::markdown('B', ['date' => '2026-10-16', 'external_url' => 'https://blog.example.test/same']),
        ]);

        try {
            $this->check($root);
            $this->fail('Expected a ContentException.');
        } catch (ContentException $exception) {
            $this->assertStringContainsString('content/articles/ext-b.md', $exception->getMessage());
            $this->assertStringContainsString('same feed id', $exception->getMessage());
        }
    }
}
