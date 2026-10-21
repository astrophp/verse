<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Support;

use Astro\Verse\Build\BuildContext;
use Astro\Verse\Build\Builder;
use Astro\Verse\Build\BuildMode;
use Astro\Verse\Build\BuildResult;
use Astro\Verse\Build\Filesystem;
use Astro\Verse\Build\LocalFilesystem;
use Astro\Verse\Config\ConfigLoader;
use Astro\Verse\Site\SiteLoader;
use Astro\Verse\Site\SiteModel;
use Astro\Verse\Support\Warnings;
use Astro\Verse\Theme\ThemeRegistry;
use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use PHPUnit\Framework\TestCase as BaseTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

abstract class TestCase extends BaseTestCase
{
    public const string SITE_YAML = "title: Test Site\nurl: https://example.test/\n";

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            self::removeTree($directory);
        }
        $this->temporaryDirectories = [];
    }

    /**
     * Creates a temporary site root from a map of relative path => file contents.
     *
     * @param  array<string, string>  $files
     */
    protected function makeSite(array $files = []): string
    {
        $root = sys_get_temp_dir().'/verse-test-'.bin2hex(random_bytes(6));
        mkdir($root, 0775, true);
        $this->temporaryDirectories[] = $root;
        // macOS reports /var as /private/var; keep one spelling
        $root = realpath($root) ?: $root;

        $this->writeFiles($root, $files);

        return $root;
    }

    /**
     * A registry over a temporary themes root; keys are paths below it, e.g. `paper/theme.yaml`.
     *
     * @param  array<string, string>  $files
     */
    protected function themeRegistry(array $files): ThemeRegistry
    {
        return new ThemeRegistry([$this->makeSite($files)]);
    }

    /**
     * @param  array<string, string>  $files
     */
    protected function writeFiles(string $root, array $files): void
    {
        foreach ($files as $path => $contents) {
            $absolute = $root.'/'.$path;
            if (! is_dir(dirname($absolute))) {
                mkdir(dirname($absolute), 0775, true);
            }
            file_put_contents($absolute, $contents);
        }
    }

    /**
     * Loads a site from files; `verse.yaml` defaults to a minimal valid one.
     *
     * @param  array<string, string>  $files
     */
    protected function loadSite(array $files, ?string $now = '2026-10-20T12:00:00Z', bool $drafts = false, ?Warnings $warnings = null, ?string $root = null): SiteModel
    {
        $root ??= $this->makeSite($files + ['verse.yaml' => self::SITE_YAML]);
        if (! is_dir($root.'/content')) {
            mkdir($root.'/content', 0775, true);
        }

        $config = (new ConfigLoader)->load($root, requireUrl: false);
        $context = BuildContext::create(
            $root,
            $config,
            $drafts ? BuildMode::Preview : BuildMode::Production,
            $drafts,
            $now === null ? null : new DateTimeImmutable($now, new DateTimeZone('UTC')),
        );

        return (new SiteLoader)->load($context, $warnings ?? new Warnings);
    }

    /**
     * Front matter + body helper.
     *
     * @param  array<string, string>  $fields  raw YAML lines: key => value
     */
    protected static function markdown(string $title, array $fields = [], string $body = "Body.\n"): string
    {
        $lines = ['---', 'title: '.$title];
        foreach ($fields as $key => $value) {
            $lines[] = $key.': '.$value;
        }
        $lines[] = '---';

        return implode("\n", $lines)."\n".$body;
    }

    /**
     * A temporary copy of a fixture site (`tests/Fixtures/sites/<name>`), with files added or replaced.
     * A null value deletes the file.
     *
     * @param  array<string, ?string>  $changes  relative path => contents
     */
    protected function fixtureSite(string $name = 'notes', array $changes = []): string
    {
        $root = $this->makeSite();
        // a fixture built by hand (`verse build` inside it) must not leak its output into tests
        self::copyTree(dirname(__DIR__).'/Fixtures/sites/'.$name, $root, ['dist', '.verse']);
        foreach ($changes as $path => $contents) {
            if ($contents === null) {
                self::removeTree($root.'/'.$path);

                continue;
            }
            $this->writeFiles($root, [$path => $contents]);
        }

        return $root;
    }

    /**
     * Builds the site at `$root` as production (or as a drafts preview) with a fixed `$now`.
     */
    protected function buildSite(
        string $root,
        string $now = '2026-10-20T12:00:00Z',
        ?ThemeRegistry $themes = null,
        ?Filesystem $fs = null,
        bool $drafts = false,
    ): BuildResult {
        $themes ??= ThemeRegistry::bundled();
        $config = (new ConfigLoader($themes))->load($root, requireUrl: true);
        $context = BuildContext::create(
            $root,
            $config,
            $drafts ? BuildMode::Preview : BuildMode::Production,
            $drafts,
            new DateTimeImmutable($now, new DateTimeZone('UTC')),
            $drafts ? $root.'/.verse/preview/drafts' : null,
        );

        return (new Builder($themes, $fs ?? new LocalFilesystem))->build($context);
    }

    /**
     * Every file below `$directory` as relative path => contents, sorted by path.
     *
     * @return array<string, string>
     */
    protected static function snapshot(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $files[substr((string) $file->getPathname(), strlen($directory) + 1)] = (string) file_get_contents((string) $file->getPathname());
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param  list<string>  $skip  top-level entry names to leave out
     */
    protected static function copyTree(string $from, string $to, array $skip = []): void
    {
        if (! is_dir($to)) {
            mkdir($to, 0775, true);
        }
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, $skip, true)) {
                continue;
            }
            is_dir($from.'/'.$entry)
                ? self::copyTree($from.'/'.$entry, $to.'/'.$entry)
                : copy($from.'/'.$entry, $to.'/'.$entry);
        }
    }

    protected static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path.'/'.$entry);
            }
        }
        rmdir($path);
    }
}
