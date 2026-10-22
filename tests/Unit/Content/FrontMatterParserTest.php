<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Content;

use Astro\Verse\Content\FrontMatterParser;
use Astro\Verse\Content\ParsedDocument;
use Astro\Verse\Error\ContentException;
use Astro\Verse\Error\ErrorKind;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrontMatterParserTest extends TestCase
{
    private const string PATH = 'content/articles/x.md';

    private function parse(string $frontMatter, string $body = "Body\n", string $timezone = 'UTC'): ParsedDocument
    {
        return (new FrontMatterParser)->parse("---\n{$frontMatter}\n---\n{$body}", self::PATH, new DateTimeZone($timezone));
    }

    private function assertError(string $frontMatter, ?string $field, string $messagePart = ''): void
    {
        try {
            $this->parse($frontMatter);
        } catch (ContentException $exception) {
            $this->assertSame(ErrorKind::Content, $exception->kind());
            $this->assertSame(self::PATH, $exception->sourceFile);
            $this->assertSame($field, $exception->field, $exception->getMessage());
            $this->assertStringContainsString('content error in '.self::PATH, $exception->getMessage());
            $this->assertStringContainsString($messagePart, $exception->getMessage());

            return;
        }
        $this->fail("Expected a ContentException for:\n".$frontMatter);
    }

    #[Test]
    public function it_parses_a_minimal_document(): void
    {
        $document = $this->parse('title: Hello');

        $this->assertSame('Hello', $document->frontMatter->title);
        $this->assertSame("Body\n", $document->body);
        $this->assertNull($document->frontMatter->date);
        $this->assertFalse($document->frontMatter->draft);
        $this->assertFalse($document->frontMatter->menu);
        $this->assertSame([], $document->frontMatter->tags);
        $this->assertSame([], $document->frontMatter->extra);
        $this->assertFalse($document->frontMatter->isExternal());
    }

    #[Test]
    public function it_parses_every_field(): void
    {
        $document = $this->parse(<<<'YAML'
            title: Full
            description: A description
            date: 2026-10-15
            updated: 2026-10-16T08:00:00Z
            draft: true
            tags: [PHP, " Laravel "]
            slug: my-slug
            permalink: /custom/place/
            canonical: https://medium.com/@x/full
            image: cover.png
            menu: true
            order: 3
            extra:
              series: one
            YAML);

        $meta = $document->frontMatter;
        $this->assertSame('A description', $meta->description);
        $this->assertSame('2026-10-15T00:00:00+00:00', $meta->date?->format('c'));
        $this->assertSame('2026-10-16T08:00:00+00:00', $meta->updated?->format('c'));
        $this->assertTrue($meta->draft);
        $this->assertSame(['PHP', 'Laravel'], $meta->tags);
        $this->assertSame('my-slug', $meta->slug);
        $this->assertSame('/custom/place/', $meta->permalink);
        $this->assertSame('https://medium.com/@x/full', $meta->canonical);
        $this->assertSame('cover.png', $meta->image);
        $this->assertTrue($meta->menu);
        $this->assertSame(3, $meta->order);
        $this->assertSame(['series' => 'one'], $meta->extra);
    }

    #[Test]
    public function urls_lose_surrounding_whitespace_such_as_a_block_scalar_newline(): void
    {
        $meta = $this->parse(<<<'YAML'
            title: x
            date: 2026-10-15
            canonical: |
              https://elsewhere.test/post
            image: "  https://elsewhere.test/cover.png  "
            YAML)->frontMatter;
        $this->assertSame('https://elsewhere.test/post', $meta->canonical);
        $this->assertSame('https://elsewhere.test/cover.png', $meta->image);

        $external = $this->parse("title: x\ndate: 2026-10-15\nexternal_url: >\n  https://elsewhere.test/out\n")->frontMatter;
        $this->assertSame('https://elsewhere.test/out', $external->externalUrl);
    }

    #[Test]
    public function it_strips_a_bom_and_normalizes_crlf(): void
    {
        $document = (new FrontMatterParser)->parse("\u{FEFF}---\r\ntitle: Win\r\ndate: 2026-10-15\r\n---\r\nLine one\r\nLine two\r\n", self::PATH, new DateTimeZone('UTC'));

        $this->assertSame('Win', $document->frontMatter->title);
        $this->assertSame("Line one\nLine two\n", $document->body);
    }

    #[Test]
    public function it_keeps_dashes_in_the_body(): void
    {
        $document = $this->parse('title: T', "Intro\n\n---\n\nAfter the rule\n");

        $this->assertSame("Intro\n\n---\n\nAfter the rule\n", $document->body);
    }

    #[Test]
    public function it_requires_front_matter_and_a_title(): void
    {
        foreach (["# Just markdown\n", "\n---\ntitle: x\n---\n", ''] as $source) {
            try {
                (new FrontMatterParser)->parse($source, self::PATH, new DateTimeZone('UTC'));
                $this->fail('Expected a ContentException');
            } catch (ContentException $exception) {
                $this->assertStringContainsString('must start with front matter', $exception->getMessage());
                $this->assertStringContainsString('title is required', $exception->getMessage());
            }
        }

        $this->assertError('description: no title', 'title', 'required');
        $this->assertError('title: ""', 'title', 'empty');
        $this->assertError('', 'title', 'required');
    }

    #[Test]
    public function it_requires_a_closing_delimiter(): void
    {
        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('not closed');

        (new FrontMatterParser)->parse("---\ntitle: x\nBody\n", self::PATH, new DateTimeZone('UTC'));
    }

    #[Test]
    public function it_rejects_unknown_keys_suggesting_extra(): void
    {
        $this->assertError("title: x\nseries: one", 'series', 'extra');
        $this->assertError("title: x\nlayout: wide", 'layout', 'unknown key "layout"');
        $this->assertError("title: x\nauthor: me", 'author');
    }

    #[Test]
    public function type_is_an_ordinary_unknown_key(): void
    {
        foreach (['type: article', 'type: page', 'type: 5'] as $line) {
            $this->assertError("title: x\n{$line}", 'type', 'unknown key "type" (put custom values under "extra").');
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function typeErrors(): iterable
    {
        yield 'title number' => ['title: 5', 'title'];
        yield 'title list' => ['title: [a]', 'title'];
        yield 'description list' => ["title: x\ndescription: [a]", 'description'];
        yield 'draft string' => ["title: x\ndraft: 'yes'", 'draft'];
        yield 'draft number' => ["title: x\ndraft: 1", 'draft'];
        yield 'tags string' => ["title: x\ntags: php", 'tags'];
        yield 'tags map' => ["title: x\ntags: {a: b}", 'tags'];
        yield 'tag number' => ["title: x\ntags: [2026]", 'tags'];
        yield 'tag empty' => ["title: x\ntags: ['']", 'tags'];
        yield 'tag blank' => ["title: x\ntags: ['  ']", 'tags'];
        yield 'tag null' => ["title: x\ntags: [~]", 'tags'];
        yield 'tag duplicate' => ["title: x\ntags: [php, php]", 'tags'];
        yield 'tags null' => ["title: x\ntags:", 'tags'];
        yield 'slug with slash' => ["title: x\nslug: a/b", 'slug'];
        yield 'slug with percent' => ["title: x\nslug: 'a%20b'", 'slug'];
        yield 'slug with question mark' => ["title: x\nslug: 'a?b'", 'slug'];
        yield 'slug with hash' => ["title: x\nslug: 'a#b'", 'slug'];
        yield 'slug with backslash' => ["title: x\nslug: 'a\\\\b'", 'slug'];
        yield 'slug starting with dot' => ["title: x\nslug: '.hidden'", 'slug'];
        yield 'slug number' => ["title: x\nslug: 5", 'slug'];
        yield 'permalink no trailing slash' => ["title: x\npermalink: /a/b", 'permalink'];
        yield 'permalink relative' => ["title: x\npermalink: a/b/", 'permalink'];
        yield 'permalink root' => ["title: x\npermalink: /", 'permalink'];
        yield 'permalink empty segment' => ["title: x\npermalink: /a//b/", 'permalink'];
        yield 'permalink dot dot' => ["title: x\npermalink: /a/../b/", 'permalink'];
        yield 'permalink dot segment' => ["title: x\npermalink: /a/./b/", 'permalink'];
        yield 'permalink percent' => ["title: x\npermalink: '/a%2Fb/'", 'permalink'];
        yield 'permalink file' => ["title: x\npermalink: /a/b.html", 'permalink'];
        yield 'permalink assets theme' => ["title: x\npermalink: /assets/theme/x/", 'permalink'];
        yield 'permalink verse' => ["title: x\npermalink: /__verse/status/", 'permalink'];
        yield 'permalink assets theme uppercase' => ["title: x\npermalink: /ASSETS/THEME/x/", 'permalink'];
        yield 'permalink assets theme mixed case' => ["title: x\npermalink: /Assets/Theme/x/", 'permalink'];
        yield 'permalink verse uppercase' => ["title: x\npermalink: /__VERSE/status/", 'permalink'];
        yield 'canonical relative' => ["title: x\ncanonical: /a/", 'canonical'];
        yield 'canonical javascript' => ["title: x\ncanonical: 'javascript:alert(1)'", 'canonical'];
        yield 'canonical ftp' => ["title: x\ncanonical: ftp://a.test/", 'canonical'];
        yield 'external relative' => ["title: x\nexternal_url: /a/", 'external_url'];
        yield 'external no host' => ["title: x\nexternal_url: 'https://'", 'external_url'];
        yield 'image javascript' => ["title: x\nimage: 'javascript:alert(1)'", 'image'];
        yield 'image data' => ["title: x\nimage: 'data:image/png;base64,AAAA'", 'image'];
        yield 'image protocol relative' => ["title: x\nimage: '//evil.test/a.png'", 'image'];
        yield 'image list' => ["title: x\nimage: [a]", 'image'];
        yield 'menu string' => ["title: x\nmenu: 'true'", 'menu'];
        yield 'order string' => ["title: x\norder: '1'", 'order'];
        yield 'order float' => ["title: x\norder: 1.5", 'order'];
        yield 'order bool' => ["title: x\norder: true", 'order'];
        yield 'extra list' => ["title: x\nextra: [a]", 'extra'];
        yield 'extra string' => ["title: x\nextra: nope", 'extra'];
    }

    #[Test]
    #[DataProvider('typeErrors')]
    public function it_rejects_wrongly_typed_fields(string $frontMatter, string $field): void
    {
        $this->assertError($frontMatter, $field);
    }

    #[Test]
    public function it_accepts_valid_image_slug_and_permalink_forms(): void
    {
        foreach (['https://cdn.test/a.png', '/images/a.png', 'cover.png', 'img/cover.png'] as $image) {
            $this->assertSame($image, $this->parse("title: x\nimage: {$image}")->frontMatter->image);
        }

        $meta = $this->parse("title: x\nslug: çalışma\npermalink: /notes/çalışma/")->frontMatter;
        $this->assertSame('çalışma', $meta->slug);
        $this->assertSame('/notes/çalışma/', $meta->permalink);

        $this->assertSame('/assets/x/', $this->parse("title: x\npermalink: /assets/x/")->frontMatter->permalink);
    }

    #[Test]
    public function it_normalizes_slug_permalink_and_tags_to_nfc(): void
    {
        $nfd = "cafe\u{301}";
        $meta = $this->parse("title: x\nslug: {$nfd}\npermalink: /{$nfd}/\ntags: [{$nfd}]")->frontMatter;

        $this->assertSame("caf\u{e9}", $meta->slug);
        $this->assertSame("/caf\u{e9}/", $meta->permalink);
        $this->assertSame(["caf\u{e9}"], $meta->tags);
    }

    #[Test]
    public function it_rejects_external_url_combined_with_permalink(): void
    {
        $this->assertError("title: x\nexternal_url: https://medium.com/a\npermalink: /a/b/", 'permalink', 'external_url');
        $this->assertSame(
            'https://medium.com/a',
            $this->parse("title: x\nexternal_url: https://medium.com/a")->frontMatter->externalUrl,
        );
    }

    #[Test]
    public function invalid_core_fields_fail_even_on_draft_pages(): void
    {
        $this->assertError("title: x\ndraft: true\ndate: 2026-02-30", 'date');
        $this->assertError("title: x\ndraft: true\ntags: 5", 'tags');
        $this->assertError("title: x\ndraft: true\nunknown: 1", 'unknown');
        $this->assertError('draft: true', 'title');
        $this->assertError("title: x\ndraft: true\nslug: a/b", 'slug');
    }

    // --- dates ------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string, string}> value as written, timezone, expected ISO-8601 instant in UTC
     */
    public static function supportedDates(): iterable
    {
        yield 'date only' => ['2026-10-15', 'UTC', '2026-10-15T00:00:00+00:00'];
        yield 'date only in Istanbul' => ['2026-10-15', 'Europe/Istanbul', '2026-10-14T21:00:00+00:00'];
        yield 'date only in New York' => ['2026-10-15', 'America/New_York', '2026-10-15T04:00:00+00:00'];
        yield 'quoted single' => ["'2026-10-15'", 'UTC', '2026-10-15T00:00:00+00:00'];
        yield 'quoted double' => ['"2026-10-15"', 'UTC', '2026-10-15T00:00:00+00:00'];
        yield 'timestamp minutes' => ['2026-10-15T09:30', 'UTC', '2026-10-15T09:30:00+00:00'];
        yield 'timestamp minutes local' => ['2026-10-15T09:30', 'Europe/Istanbul', '2026-10-15T06:30:00+00:00'];
        yield 'timestamp seconds' => ['2026-10-15T09:30:45', 'UTC', '2026-10-15T09:30:45+00:00'];
        yield 'timestamp seconds local' => ['2026-10-15T09:30:45', 'Europe/Istanbul', '2026-10-15T06:30:45+00:00'];
        yield 'Z' => ['2026-10-15T09:30:00Z', 'Europe/Istanbul', '2026-10-15T09:30:00+00:00'];
        yield 'Z minutes' => ['2026-10-15T09:30Z', 'Europe/Istanbul', '2026-10-15T09:30:00+00:00'];
        yield 'positive offset' => ['2026-10-15T09:30:00+02:00', 'America/New_York', '2026-10-15T07:30:00+00:00'];
        yield 'negative offset' => ['2026-10-15T09:30:00-05:30', 'UTC', '2026-10-15T15:00:00+00:00'];
        yield 'offset minutes' => ['2026-10-15T09:30+03:00', 'UTC', '2026-10-15T06:30:00+00:00'];
        yield 'quoted timestamp' => ['"2026-10-15T09:30:00Z"', 'UTC', '2026-10-15T09:30:00+00:00'];
        yield 'quoted offset' => ["'2026-10-15T09:30:00+02:00'", 'UTC', '2026-10-15T07:30:00+00:00'];
        yield 'leap day' => ['2028-02-29', 'UTC', '2028-02-29T00:00:00+00:00'];
        yield 'trailing comment' => ['2026-10-15 # published', 'UTC', '2026-10-15T00:00:00+00:00'];
        yield 'quoted with comment' => ["'2026-10-15'   # published", 'UTC', '2026-10-15T00:00:00+00:00'];
        yield 'trailing spaces' => ['2026-10-15   ', 'UTC', '2026-10-15T00:00:00+00:00'];
        yield 'end of day' => ['2026-12-31T23:59:59', 'UTC', '2026-12-31T23:59:59+00:00'];
    }

    #[Test]
    #[DataProvider('supportedDates')]
    public function it_reads_every_supported_date_form(string $written, string $timezone, string $expectedUtc): void
    {
        foreach (['date', 'updated'] as $key) {
            $meta = $this->parse("title: x\n{$key}: {$written}", timezone: $timezone)->frontMatter;
            $value = $key === 'date' ? $meta->date : $meta->updated;

            $this->assertNotNull($value, $written);
            $this->assertSame($expectedUtc, $value->setTimezone(new DateTimeZone('UTC'))->format('c'), $written);
        }
    }

    private function dateOf(string $frontMatter, string $timezone = 'UTC'): \DateTimeImmutable
    {
        return $this->parse($frontMatter, timezone: $timezone)->frontMatter->date ?? $this->fail('Expected a date');
    }

    #[Test]
    public function it_keeps_the_offset_zone_of_timestamps_and_the_site_zone_for_local_values(): void
    {
        $this->assertSame('+02:00', $this->dateOf("title: x\ndate: 2026-10-15T09:30:00+02:00")->format('P'));
        $this->assertSame('+03:00', $this->dateOf("title: x\ndate: 2026-10-15", 'Europe/Istanbul')->format('P'));
        $this->assertSame('Europe/Istanbul', $this->dateOf("title: x\ndate: 2026-10-15", 'Europe/Istanbul')->getTimezone()->getName());
    }

    #[Test]
    public function the_date_line_may_appear_anywhere_among_the_fields(): void
    {
        $meta = $this->parse("title: x\ndescription: |\n  multi\n  line\ndate: 2026-10-15\ntags: [a]")->frontMatter;

        $this->assertSame('2026-10-15', $meta->date?->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCalendarDates(): iterable
    {
        yield 'february 30' => ['2026-02-30'];
        yield 'february 29 non leap' => ['2026-02-29'];
        yield 'april 31' => ['2026-04-31'];
        yield 'month 13 quoted' => ['"2026-13-01"'];
        yield 'month 0' => ['2026-00-10'];
        yield 'day 0' => ['2026-10-00'];
        yield 'hour 24' => ['2026-10-15T24:00'];
        yield 'minute 60' => ['2026-10-15T09:60'];
        yield 'second 60' => ['2026-10-15T09:30:60'];
        yield 'offset hour 25' => ['2026-10-15T09:30+25:00'];
        yield 'offset minute 60' => ['2026-10-15T09:30+02:60'];
        yield 'quoted february 30' => ['"2026-02-30"'];
    }

    #[Test]
    #[DataProvider('invalidCalendarDates')]
    public function it_validates_the_calendar_strictly(string $written): void
    {
        $this->assertError("title: x\ndate: {$written}", 'date', 'valid calendar date');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedDateForms(): iterable
    {
        yield 'space separated' => ['date: 2026-10-15 09:30'];
        yield 'space separated seconds' => ['date: 2026-10-15 09:30:00'];
        yield 'flow mapping' => ['date: {year: 2026, month: 10, day: 15}'];
        yield 'flow list' => ['date: [2026-10-15]'];
        yield 'anchor' => ['date: &d 2026-10-15'];
        yield 'tagged' => ['date: !!str 2026-10-15'];
        yield 'fractional seconds' => ['date: 2026-10-15T09:30:00.123Z'];
        yield 'lowercase t' => ['date: 2026-10-15t09:30'];
        yield 'lowercase z' => ['date: 2026-10-15T09:30z'];
        yield 'compact' => ['date: 20261015'];
        yield 'slashes' => ['date: 2026/10/15'];
        yield 'text' => ['date: soon'];
        yield 'null' => ['date:'];
        yield 'tilde' => ['date: ~'];
        yield 'quoted key' => ['"date": 2026-10-15'];
        yield 'block scalar' => ["date: |\n  2026-10-15"];
        yield 'multi line' => ["date:\n  2026-10-15"];
        yield 'hash without space' => ['date: 2026-10-15#note'];
        yield 'offset without colon' => ['date: 2026-10-15T09:30+0200'];
        yield 'two digit year' => ['date: 26-10-15'];
    }

    #[Test]
    #[DataProvider('unsupportedDateForms')]
    public function it_rejects_every_unsupported_date_form(string $line): void
    {
        $this->assertError("title: x\n{$line}", 'date', 'ISO 8601');
    }

    #[Test]
    public function yaml_syntax_errors_in_dates_are_content_errors_naming_the_file(): void
    {
        foreach (['date: 2026-13-01', 'date: "2026-10-15\'', "extra:\n  note: 1\n date: 2026-10-15"] as $line) {
            $this->assertError("title: x\n{$line}", null, 'invalid YAML');
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function misleadingDateLines(): iterable
    {
        foreach (['date', 'updated'] as $key) {
            yield $key.' inside a quoted title, real key quoted' => ["title: \"Intro\n{$key}: 2020-01-01\nend\"\n\"{$key}\": 2030-01-01", $key];
            yield $key.' inside a quoted title, real key a flow value' => ["title: \"Intro\n{$key}: 2020-01-01\nend\"\n{$key}: {y: 2030}", $key];
            yield $key.' with an indented continuation' => ["title: x\n{$key}: 2026-10-20\n  continued", $key];
            yield $key.' inside a single quoted description' => ["title: x\ndescription: 'a\n{$key}: 2020-01-01\nb'\n\"{$key}\": 2030-01-01", $key];
        }
    }

    #[Test]
    #[DataProvider('misleadingDateLines')]
    public function a_matched_date_line_must_be_the_real_top_level_key(string $frontMatter, string $key): void
    {
        $this->assertError($frontMatter, $key, 'unsupported date format');
    }

    #[Test]
    public function a_date_like_line_inside_another_scalar_does_not_change_a_real_date(): void
    {
        $meta = $this->parse("title: \"Intro\nnote: 2020-01-01\nend\"\ndate: 2026-10-20")->frontMatter;

        $this->assertSame('2026-10-20', $meta->date?->format('Y-m-d'));
    }

    #[Test]
    public function the_space_form_is_rejected_with_the_fix_in_the_message(): void
    {
        $this->assertError("title: x\ndate: 2026-10-15 09:30", 'date', '`date: YYYY-MM-DD`');
    }

    #[Test]
    public function updated_follows_the_same_rules(): void
    {
        $this->assertError("title: x\nupdated: 2026-02-30", 'updated', 'valid calendar date');
        $this->assertError("title: x\nupdated: 2026-10-15 09:30", 'updated', '`updated: YYYY-MM-DD`');
        $this->assertError("title: x\nupdated: {a: 1}", 'updated');
    }

    #[Test]
    public function a_date_inside_extra_does_not_count_as_the_top_level_date(): void
    {
        $meta = $this->parse("title: x\ndate: 2026-10-15\nextra:\n  date: 2020-01-01")->frontMatter;

        $this->assertSame('2026-10-15', $meta->date?->format('Y-m-d'));
    }
}
