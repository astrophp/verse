<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Build;

use Astro\Verse\Build\BuildContext;
use Astro\Verse\Build\Builder;
use Astro\Verse\Build\BuildResult;
use Astro\Verse\Build\Filesystem;
use Astro\Verse\Build\LocalFilesystem;
use Astro\Verse\Config\ConfigLoader;
use Astro\Verse\Error\ContentException;
use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Support\Warnings;
use Astro\Verse\Tests\Support\FailingFilesystem;
use Astro\Verse\Tests\Support\TestCase;
use Astro\Verse\Theme\ThemeRegistry;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;

final class CheckSiteTest extends TestCase
{
    private const string BROKEN = "---\ntitle: Broken\ndate: soon\n---\nText\n";

    private function check(string $root, string $tempRoot, ?Filesystem $fs = null, ?Warnings $warnings = null): BuildResult
    {
        $builder = new Builder(ThemeRegistry::bundled(), $fs ?? new LocalFilesystem, tempRoot: $tempRoot);

        return $builder->checkSite(
            static fn (): BuildContext => BuildContext::create(
                $root,
                (new ConfigLoader)->load($root, requireUrl: true),
                now: new DateTimeImmutable('2026-10-20T12:00:00Z', new DateTimeZone('UTC')),
            ),
            $warnings,
        );
    }

    /**
     * @return list<string> the names in a directory
     */
    private static function entries(string $directory): array
    {
        return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
    }

    /**
     * A filesystem that cannot delete the check's own directory (a direct child of the temporary root).
     */
    private function undeletable(string $tempRoot): Filesystem
    {
        return new FailingFilesystem(
            static fn (): bool => false,
            static fn (string $path): bool => dirname($path) === $tempRoot,
        );
    }

    #[Test]
    public function a_passing_check_renders_the_site_and_leaves_the_temporary_root_empty(): void
    {
        $root = $this->fixtureSite();
        $tempRoot = $this->makeSite();
        $before = self::snapshot($root);

        $result = $this->check($root, $tempRoot);

        $this->assertGreaterThan(1, $result->pageCount);
        $this->assertSame($root.'/dist', $result->outputDir);
        $this->assertSame([], self::entries($tempRoot));
        $this->assertSame($before, self::snapshot($root));
        $this->assertDirectoryDoesNotExist($root.'/.verse');
        $this->assertDirectoryDoesNotExist($root.'/dist');
    }

    #[Test]
    public function a_failing_check_leaves_the_temporary_root_empty(): void
    {
        $root = $this->fixtureSite('notes', ['content/articles/broken.md' => self::BROKEN]);
        $tempRoot = $this->makeSite();

        try {
            $this->check($root, $tempRoot);
            $this->fail('Expected a ContentException');
        } catch (ContentException $exception) {
            $this->assertStringContainsString('content/articles/broken.md', $exception->getMessage());
        }

        $this->assertSame([], self::entries($tempRoot));
        $this->assertDirectoryDoesNotExist($root.'/.verse');
    }

    #[Test]
    public function a_symlinked_temporary_root_works(): void
    {
        $root = $this->fixtureSite();
        $real = $this->makeSite();
        $link = $this->makeSite().'/link';
        symlink($real, $link);

        $this->check($root, $link);

        $this->assertSame([], self::entries($real));
    }

    #[Test]
    public function a_temporary_root_inside_the_site_is_refused_before_anything_is_written(): void
    {
        $root = $this->fixtureSite();
        $before = self::snapshot($root);

        try {
            $this->check($root, $root.'/scratch');
            $this->fail('Expected a FilesystemException');
        } catch (FilesystemException $exception) {
            $this->assertStringContainsString('is inside the site', $exception->getMessage());
            $this->assertStringContainsString('TMPDIR', $exception->getMessage());
        }

        $this->assertDirectoryDoesNotExist($root.'/scratch');
        $this->assertSame($before, self::snapshot($root));
    }

    #[Test]
    public function a_temporary_root_that_does_not_exist_is_a_filesystem_error(): void
    {
        $root = $this->fixtureSite();
        $tempRoot = $this->makeSite().'/missing';

        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessage('the temporary directory '.$tempRoot.' does not exist');

        $this->check($root, $tempRoot);
    }

    #[Test]
    public function a_temporary_root_that_reaches_into_the_site_through_a_link_and_dot_dot_is_refused(): void
    {
        $root = $this->fixtureSite();
        mkdir($root.'/.verse/cache', 0775, true);
        $outside = $this->makeSite();
        symlink($root.'/.verse/cache', $outside.'/link');
        $before = self::snapshot($root);

        try {
            // `link/..` is `<site>/.verse` on disk, although the path text says `$outside`
            $this->check($root, $outside.'/link/..');
            $this->fail('Expected a FilesystemException');
        } catch (FilesystemException $exception) {
            $this->assertStringContainsString('is inside the site', $exception->getMessage());
        }

        $this->assertSame(['cache'], self::entries($root.'/.verse'));
        $this->assertSame($before, self::snapshot($root));
    }

    #[Test]
    public function a_failed_removal_after_a_passing_check_fails_the_check(): void
    {
        $root = $this->fixtureSite();
        $tempRoot = $this->makeSite();

        try {
            $this->check($root, $tempRoot, $this->undeletable($tempRoot));
            $this->fail('Expected a FilesystemException');
        } catch (FilesystemException $exception) {
            $this->assertStringContainsString('injected failure', $exception->getMessage());
            $this->assertStringContainsString($tempRoot.'/verse-check-', $exception->getMessage());
        }
    }

    #[Test]
    public function a_failed_removal_after_a_content_error_keeps_the_original_error_and_warns(): void
    {
        $root = $this->fixtureSite('notes', ['content/articles/broken.md' => self::BROKEN]);
        $tempRoot = $this->makeSite();
        $warnings = new Warnings;

        try {
            $this->check($root, $tempRoot, $this->undeletable($tempRoot), $warnings);
            $this->fail('Expected a ContentException');
        } catch (ContentException $exception) {
            $this->assertSame('broken.md', basename((string) $exception->sourceFile));
            $this->assertSame('date', $exception->field);
        }

        $messages = array_values(array_filter($warnings->all(), static fn (string $message): bool => str_starts_with($message, 'could not remove the temporary directory ')));
        $this->assertCount(1, $messages);
        $this->assertStringContainsString($tempRoot.'/verse-check-', $messages[0]);
        $this->assertStringContainsString('injected failure', $messages[0]);
    }

    #[Test]
    public function a_symlinked_content_folder_is_refused(): void
    {
        $root = $this->fixtureSite();
        $elsewhere = $this->makeSite();
        self::copyTree($root.'/content', $elsewhere.'/content');
        self::removeTree($root.'/content');
        symlink($elsewhere.'/content', $root.'/content');
        $tempRoot = $this->makeSite();

        try {
            $this->check($root, $tempRoot);
            $this->fail('Expected a FilesystemException');
        } catch (FilesystemException $exception) {
            $this->assertSame('content', $exception->sourceFile);
            $this->assertStringContainsString('symbolic links are not supported', $exception->getMessage());
        }

        $this->assertSame([], self::entries($tempRoot));
    }

    #[Test]
    public function a_symlinked_view_cache_is_ignored_by_a_check(): void
    {
        $root = $this->fixtureSite();
        $elsewhere = $this->makeSite(['kept.txt' => 'kept']);
        mkdir($root.'/.verse/cache', 0775, true);
        symlink($elsewhere, $root.'/.verse/cache/views');
        $tempRoot = $this->makeSite();

        $this->check($root, $tempRoot);

        $this->assertTrue(is_link($root.'/.verse/cache/views'));
        $this->assertSame(['kept.txt' => 'kept'], self::snapshot($elsewhere));
    }

    #[Test]
    public function a_build_that_fails_on_content_leaves_the_view_cache_alone(): void
    {
        $root = $this->fixtureSite('notes', [
            'content/articles/broken.md' => self::BROKEN,
            '.verse/cache/views/seed.php' => 'cached',
        ]);

        try {
            $this->buildSite($root);
            $this->fail('Expected a ContentException');
        } catch (ContentException) {
            // expected
        }

        $this->assertSame('cached', file_get_contents($root.'/.verse/cache/views/seed.php'));
    }
}
