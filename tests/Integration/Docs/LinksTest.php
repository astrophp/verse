<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Docs;

use Astro\Verse\Tests\Support\Docs\Pages;
use Astro\Verse\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every relative link in the README, the guides in docs/, CONTRIBUTING.md, CHANGELOG.md, ROADMAP.md
 * and SECURITY.md names a file or directory that exists, and a heading anchor names a heading that
 * the target page has. Links with a scheme (https:, mailto:) are not followed.
 */
final class LinksTest extends TestCase
{
    #[Test]
    public function there_are_pages_to_check(): void
    {
        $names = array_map('basename', Pages::all());

        $this->assertContains('README.md', $names);
        $this->assertContains('CONTRIBUTING.md', $names);
        $this->assertContains('CHANGELOG.md', $names);
        $this->assertContains('ROADMAP.md', $names);
        $this->assertContains('SECURITY.md', $names);
        $this->assertContains('releasing.md', $names);
    }

    #[Test]
    public function it_links_only_to_files_and_headings_that_exist(): void
    {
        $broken = [];
        $checked = 0;

        foreach (Pages::all() as $page) {
            $result = self::brokenTargets($page, Pages::read($page));
            array_push($broken, ...$result['broken']);
            $checked += $result['checked'];
        }

        $this->assertGreaterThan(3, $checked);
        $this->assertSame([], $broken);
    }

    #[Test]
    public function it_checks_images_the_way_it_checks_links(): void
    {
        $page = Pages::root().'/docs/releasing.md';
        $text = <<<'MD'
            ![online](https://example.com/a.png)
            ![here](releasing.md)
            ![missing](images/missing.png)
            [![badge](https://example.com/b.svg)](https://example.com/x)
            <img src="images/other-missing.png" alt="x">
            <img src="https://example.com/c.png" alt="x">
            <picture>
              <source srcset="images/dark.png 1x, https://example.com/d.png 2x" media="(prefers-color-scheme: dark)">
              <img src="releasing.md" alt="x">
            </picture>
            `![in code](images/ignored.png)`
            MD;

        $result = self::brokenTargets($page, $text);

        $this->assertSame([
            'releasing.md -> images/missing.png (no such file)',
            'releasing.md -> images/other-missing.png (no such file)',
            'releasing.md -> images/dark.png (no such file)',
        ], $result['broken']);
        $this->assertSame(5, $result['checked']);
    }

    #[Test]
    public function it_would_notice_a_link_to_a_page_or_a_heading_that_is_not_there(): void
    {
        $anchors = self::anchorsOf(Pages::read(Pages::root().'/docs/releasing.md'));

        $this->assertContains('one-time-packagist-setup-repository-owner', $anchors);
        $this->assertNotContains('no-such-heading', $anchors);
        $this->assertFileDoesNotExist(Pages::root().'/docs/no-such-page.md');
    }

    #[Test]
    public function it_reports_a_missing_heading_in_an_existing_page(): void
    {
        $page = Pages::root().'/docs/releasing.md';
        $text = '[ok](releasing.md#one-time-packagist-setup-repository-owner) [bad](releasing.md#no-such-heading) [self](#nope) [home](../README.md)';

        $this->assertSame([
            'releasing.md -> releasing.md#no-such-heading (no such heading)',
            'releasing.md -> #nope (no such heading)',
        ], self::brokenTargets($page, $text)['broken']);
    }

    /**
     * The text of a page without its fenced code blocks and inline code, which can hold link-like text.
     */
    private static function proseOf(string $text): string
    {
        $text = preg_replace('/^```.*?^```$/ms', '', $text) ?? $text;

        return preg_replace('/`[^`\n]*`/', '', $text) ?? $text;
    }

    /**
     * GitHub's anchor for each heading of a page, repeated headings numbered from -1.
     *
     * @return list<string>
     */
    private static function anchorsOf(string $text): array
    {
        $text = preg_replace('/^```.*?^```$/ms', '', $text) ?? $text;
        $anchors = [];
        $seen = [];

        preg_match_all('/^#{1,6}\s+(.+?)\s*#*$/m', $text, $headings);

        foreach ($headings[1] as $heading) {
            $heading = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $heading) ?? $heading;
            $heading = str_replace(['`', '*'], '', $heading);
            $slug = str_replace(' ', '-', preg_replace('/[^\p{L}\p{N}\s_-]/u', '', mb_strtolower($heading)) ?? '');

            $count = $seen[$slug] ?? 0;
            $seen[$slug] = $count + 1;
            $anchors[] = $count === 0 ? $slug : "{$slug}-{$count}";
        }

        return $anchors;
    }

    /**
     * Every link and image target of a page: Markdown links and images, and the src and srcset of
     * HTML tags (<img>, <source>). Targets with a scheme are not followed.
     *
     * @return list<string>
     */
    private static function targetsOf(string $text): array
    {
        $text = self::proseOf($text);
        preg_match_all('/\]\(([^)\s]+)\)/', $text, $markdown);
        preg_match_all('/\bsrc=["\']([^"\']+)["\']/i', $text, $sources);
        preg_match_all('/\bsrcset=["\']([^"\']+)["\']/i', $text, $sets);

        $targets = [...$markdown[1], ...$sources[1]];

        foreach ($sets[1] as $set) {
            foreach (explode(',', $set) as $candidate) {
                $target = strtok(trim($candidate), ' ');
                if ($target !== false) {
                    $targets[] = $target;
                }
            }
        }

        return array_values(array_filter($targets, static fn (string $target): bool => preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) !== 1));
    }

    /**
     * What is wrong with the targets of a page's links and images, naming the page.
     *
     * @return array{broken: list<string>, checked: int}
     */
    private static function brokenTargets(string $page, string $text): array
    {
        $broken = [];
        $checked = 0;

        foreach (self::targetsOf($text) as $target) {
            [$path, $anchor] = array_pad(explode('#', $target, 2), 2, null);
            $file = $path === '' ? $page : realpath(dirname($page).'/'.$path);
            $checked++;

            if ($file === false || ! file_exists($file)) {
                $broken[] = basename($page)." -> {$target} (no such file)";

                continue;
            }

            if ($anchor !== null && $anchor !== '' && str_ends_with($file, '.md') && ! in_array($anchor, self::anchorsOf(Pages::read($file)), true)) {
                $broken[] = basename($page)." -> {$target} (no such heading)";
            }
        }

        return ['broken' => $broken, 'checked' => $checked];
    }
}
