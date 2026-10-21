<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Routing;

use Astro\Verse\Error\ContentException;
use Astro\Verse\Routing\ClaimSource;
use Astro\Verse\Routing\OutputRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OutputRegistryTest extends TestCase
{
    private function assertConflict(callable $callback, string ...$messageParts): void
    {
        try {
            $callback();
        } catch (ContentException $exception) {
            $this->assertStringStartsWith('content error: output conflict:', $exception->getMessage());
            foreach ($messageParts as $part) {
                $this->assertStringContainsString($part, $exception->getMessage());
            }

            return;
        }
        $this->fail('Expected an output conflict');
    }

    #[Test]
    public function it_accepts_distinct_paths_and_lists_owners_sorted(): void
    {
        $registry = new OutputRegistry;
        $registry->claim('notes/index.html', 'listing');
        $registry->claim('about/index.html', 'about page');
        $registry->claim('about/photo.png', 'attachment');
        $registry->claim('assets/theme/app.css', 'theme css', ClaimSource::Theme);
        $registry->claim('404.html', '404', ClaimSource::System);

        $this->assertSame(
            ['404.html', 'about/index.html', 'about/photo.png', 'assets/theme/app.css', 'notes/index.html'],
            array_keys($registry->owners()),
        );
        $this->assertSame('about page', $registry->owner('about/index.html'));
        $this->assertNull($registry->owner('nope'));
    }

    #[Test]
    public function exact_duplicates_name_both_owners(): void
    {
        $registry = new OutputRegistry;
        $registry->claim('about/index.html', 'page content/about.md');

        $this->assertConflict(
            fn () => $registry->claim('about/index.html', 'page content/about/page.md'),
            'about/index.html',
            'page content/about.md',
            'page content/about/page.md',
        );
    }

    #[Test]
    public function case_insensitive_duplicates_name_both_owners(): void
    {
        $registry = new OutputRegistry;
        $registry->claim('About/index.html', 'page A');

        $this->assertConflict(
            fn () => $registry->claim('about/index.html', 'page B'),
            'letter case',
            'About/index.html',
            'about/index.html',
            'page A',
            'page B',
        );
    }

    #[Test]
    public function unicode_case_duplicates_are_detected(): void
    {
        $registry = new OutputRegistry;
        $registry->claim('Çalışma/index.html', 'page A');

        $this->assertConflict(fn () => $registry->claim('çalışma/index.html', 'page B'), 'letter case', 'page A', 'page B');
    }

    #[Test]
    public function directories_that_differ_only_by_case_conflict(): void
    {
        $registry = new OutputRegistry;
        $registry->claim('Notes/a/index.html', 'page A');

        $this->assertConflict(fn () => $registry->claim('notes/b/index.html', 'page B'), 'letter case', 'page A', 'page B');
    }

    #[Test]
    public function a_file_cannot_also_be_a_directory(): void
    {
        $registry = new OutputRegistry;
        $registry->claim('about', 'public file public/about');

        $this->assertConflict(
            fn () => $registry->claim('about/index.html', 'page content/about.md'),
            'public file public/about',
            'page content/about.md',
            'directory',
        );
    }

    #[Test]
    public function a_directory_cannot_later_be_claimed_as_a_file(): void
    {
        $registry = new OutputRegistry;
        $registry->claim('about/index.html', 'page content/about.md');

        $this->assertConflict(
            fn () => $registry->claim('about', 'public file public/about'),
            'public file public/about',
            'page content/about.md',
            'directory',
        );
    }

    #[Test]
    public function deeper_file_vs_directory_conflicts_are_detected(): void
    {
        $registry = new OutputRegistry;
        $registry->claim('post/img', 'attachment A');

        $this->assertConflict(fn () => $registry->claim('post/img/a.png', 'attachment B'), 'attachment A', 'attachment B');
    }

    #[Test]
    public function sibling_files_in_one_directory_are_fine(): void
    {
        $registry = new OutputRegistry;
        $registry->claim('notes/page/index.html', 'page');
        $registry->claim('notes/page/2/index.html', 'listing page 2');
        $registry->claim('notes/index.html', 'listing');

        $this->assertCount(3, $registry->owners());
    }

    #[Test]
    public function the_theme_asset_area_is_reserved_for_the_theme(): void
    {
        $registry = new OutputRegistry;

        $this->assertConflict(
            fn () => $registry->claim('assets/theme/app.css', 'public file public/assets/theme/app.css'),
            'assets/theme/',
            'public file public/assets/theme/app.css',
        );
        $this->assertConflict(
            fn () => $registry->claim('assets/theme/x/index.html', 'page content/assets/theme/x.md'),
            'assets/theme/',
            'content/assets/theme/x.md',
        );
        $this->assertConflict(fn () => $registry->claim('assets/theme/a', 'system', ClaimSource::System), 'assets/theme/');

        $registry->claim('assets/theme/app.css', 'theme asset', ClaimSource::Theme);
        $this->assertSame('theme asset', $registry->owner('assets/theme/app.css'));
    }

    #[Test]
    public function reserved_areas_are_matched_case_insensitively(): void
    {
        $registry = new OutputRegistry;

        $this->assertConflict(fn () => $registry->claim('ASSETS/THEME/foreign.css', 'public file X'), 'ASSETS/THEME/foreign.css', 'assets/theme/', 'public file X');
        $this->assertConflict(fn () => $registry->claim('Assets/Theme/x/index.html', 'page Y'), 'Assets/Theme/x/index.html', 'page Y');
        $this->assertConflict(fn () => $registry->claim('__VERSE/status', 'public file Z'), '__VERSE/status', '__verse/', 'public file Z');
        $this->assertConflict(fn () => $registry->claim('__Verse/x', 'system', ClaimSource::System), '__Verse/x');
        $this->assertConflict(fn () => $registry->claim('__VERSE/x', 'theme', ClaimSource::Theme), '__VERSE/x');

        $registry->claim('assets/theme/app.css', 'theme asset', ClaimSource::Theme);
        $registry->claim('assets/theme-notes/a.css', 'public file W');
        $this->assertCount(2, $registry->owners());
    }

    #[Test]
    public function other_assets_paths_are_not_reserved(): void
    {
        $registry = new OutputRegistry;
        $registry->claim('assets/images/a.png', 'public file');
        $registry->claim('assets/themes/x.css', 'public file 2');

        $this->assertCount(2, $registry->owners());
    }

    #[Test]
    public function the_preview_area_is_reserved_for_everyone(): void
    {
        $registry = new OutputRegistry;

        foreach ([ClaimSource::Content, ClaimSource::Theme, ClaimSource::System] as $source) {
            $this->assertConflict(
                fn () => $registry->claim('__verse/status', 'someone', $source),
                '__verse/',
                'someone',
            );
        }
    }

    #[Test]
    public function a_reserved_prefix_requires_the_exact_directory(): void
    {
        $registry = new OutputRegistry;
        $registry->claim('__verse-notes/index.html', 'page');
        $registry->claim('__verse', 'file named like the area');

        $this->assertCount(2, $registry->owners());
    }
}
