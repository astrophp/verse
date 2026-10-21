<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\View;

use Astro\Verse\Assets\ViteManifest;
use Astro\Verse\Error\ThemeException;
use Astro\Verse\Tests\Support\TestCase;
use Astro\Verse\Theme\ThemeRegistry;
use Astro\Verse\View\ThemeContext;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;

final class ThemeContextTest extends TestCase
{
    private function context(string $yaml = "title: T\nurl: https://example.test/notes/\n"): ThemeContext
    {
        $site = $this->loadSite(['verse.yaml' => $yaml, 'content/articles/a.md' => self::markdown('A', ['date' => '2026-01-01'])]);
        $theme = ThemeRegistry::bundled()->find('default');

        return new ThemeContext($site, $theme, ViteManifest::load($theme));
    }

    #[Test]
    public function it_generates_base_path_urls(): void
    {
        $context = $this->context();

        $this->assertSame('/notes/articles/', $context->url('/articles/'));
        $this->assertSame('/notes/humans.txt', $context->asset('humans.txt'));
        $this->assertSame('https://example.test/notes/articles/', $context->canonical('/articles/'));
        $this->assertStringContainsString('href="/notes/assets/theme/app-', $context->assetTags());
    }

    #[Test]
    public function inline_reads_theme_files_and_refuses_to_leave_the_theme(): void
    {
        $context = $this->context();

        $this->assertStringContainsString('verse-theme', $context->inline('src/head-theme.js'));
        foreach (['../../composer.json', '/etc/passwd', 'src/missing.js', 'src/../../../composer.json', 'src'] as $path) {
            try {
                $context->inline($path);
                $this->fail('Expected a ThemeException for '.$path);
            } catch (ThemeException $exception) {
                $this->assertStringContainsString('inside the theme folder', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function icons_are_known_or_a_theme_error(): void
    {
        $context = $this->context();

        $this->assertSame(
            '<svg class="icon small" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 18 18 6M6 6h12v12"/></svg>',
            $context->icon('up-right', 'small'),
        );
        $this->expectException(ThemeException::class);
        $context->icon('nope');
    }

    #[Test]
    public function dates_use_the_site_language_and_timezone(): void
    {
        $date = new DateTimeImmutable('2026-12-31T22:30:00Z');

        $english = $this->context();
        $this->assertSame('1 Jan 2026', $this->context()->formatDate(new DateTimeImmutable('2026-01-01T12:00:00Z'), 'd MMM y'));
        $this->assertSame('31 December 2026', $english->formatDate($date, 'd MMMM y'));
        $this->assertSame('2026-12-31', $english->isoDate($date));

        $turkish = $this->context("title: T\nlanguage: tr\ntimezone: Europe/Istanbul\n");
        $this->assertSame('1 Ocak 2027', $turkish->formatDate($date, 'd MMMM y'));
        $this->assertSame('2027-01-01', $turkish->isoDate($date));
        $this->assertSame('2027', $turkish->year($date));
    }

    #[Test]
    public function it_marks_the_current_menu_item(): void
    {
        $site = $this->loadSite(['content/about.md' => self::markdown('About', ['menu' => 'true'])]);
        $theme = ThemeRegistry::bundled()->find('default');
        $real = new ThemeContext($site, $theme, ViteManifest::load($theme));
        [$home, $articles, $tags, $about] = $site->menu->items;

        $this->assertTrue($real->isCurrent($home, '/'));
        $this->assertFalse($real->isCurrent($home, '/articles/'));
        $this->assertTrue($real->isCurrent($articles, '/articles/'));
        $this->assertTrue($real->isCurrent($articles, '/articles/page/2/'));
        $this->assertFalse($real->isCurrent($articles, '/'));
        $this->assertFalse($real->isCurrent($articles, '/tags/'));
        $this->assertTrue($real->isCurrent($tags, '/tags/'));
        $this->assertTrue($real->isCurrent($tags, '/tags/php/page/2/'));
        $this->assertTrue($real->isCurrent($about, '/about/'));
        $this->assertFalse($real->isCurrent($about, '/'));
        $this->assertFalse($real->isCurrent($about, ''));
    }
}
