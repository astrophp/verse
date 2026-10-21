<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Build;

use Astro\Verse\Build\BuildContext;
use Astro\Verse\Build\Builder;
use Astro\Verse\Build\BuildMode;
use Astro\Verse\Build\LocalFilesystem;
use Astro\Verse\Build\OutputTransaction;
use Astro\Verse\Build\PathGuard;
use Astro\Verse\Config\ConfigLoader;
use Astro\Verse\Error\ContentException;
use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Support\Warnings;
use Astro\Verse\Tests\Support\FailingFilesystem;
use Astro\Verse\Tests\Support\TestCase;
use Astro\Verse\Theme\ThemeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class OutputTransactionTest extends TestCase
{
    private const string ID = '20261020-120000-0a1b2c3d';

    /**
     * @return array<string, string>
     */
    private static function tiny(string $text = 'First'): array
    {
        return [
            'verse.yaml' => TestCase::SITE_YAML,
            'content/articles/entry.md' => self::markdown($text, ['date' => '2026-01-01']),
        ];
    }

    private function assertFilesystemError(callable $callback, string ...$parts): void
    {
        try {
            $callback();
        } catch (FilesystemException $exception) {
            foreach ($parts as $part) {
                $this->assertStringContainsString($part, $exception->getMessage());
            }

            return;
        }
        $this->fail('Expected a FilesystemException');
    }

    // --- build-level behaviour ----------------------------------------------------------------------

    #[Test]
    public function a_dist_directory_without_the_marker_is_refused_and_left_untouched(): void
    {
        $root = $this->makeSite(self::tiny() + ['dist/keep.txt' => 'mine']);

        $this->assertFilesystemError(fn () => $this->buildSite($root), 'was not created by Verse', 'Move it away');

        $this->assertSame(['keep.txt' => 'mine'], self::snapshot($root.'/dist'));
        $this->assertDirectoryDoesNotExist($root.'/.verse/staging');
    }

    #[Test]
    public function a_dist_directory_with_an_unparsable_marker_is_refused(): void
    {
        $root = $this->makeSite(self::tiny() + ['dist/.verse-output' => 'not json', 'dist/keep.txt' => 'mine']);

        $this->assertFilesystemError(fn () => $this->buildSite($root), 'was not created by Verse');
        $this->assertSame(['.verse-output' => 'not json', 'keep.txt' => 'mine'], self::snapshot($root.'/dist'));
    }

    #[Test]
    public function a_symlinked_dist_is_refused(): void
    {
        $root = $this->makeSite(self::tiny());
        $target = $this->makeSite(['precious.txt' => 'keep']);
        symlink($target, $root.'/dist');

        $this->assertFilesystemError(fn () => $this->buildSite($root), 'symbolic link', 'dist');

        $this->assertSame(['precious.txt' => 'keep'], self::snapshot($target));
    }

    #[Test]
    public function a_symlinked_state_directory_is_refused(): void
    {
        $root = $this->makeSite(self::tiny());
        $target = $this->makeSite();
        symlink($target, $root.'/.verse');

        $this->assertFilesystemError(fn () => $this->buildSite($root), 'symbolic link', '.verse');
        $this->assertSame([], self::snapshot($target));
    }

    #[Test]
    public function a_second_build_replaces_the_first_and_leaves_no_state_behind(): void
    {
        $root = $this->makeSite(self::tiny());
        $this->buildSite($root);
        $first = self::snapshot($root.'/dist');
        $this->assertStringContainsString('First', $first['index.html']);

        file_put_contents($root.'/content/articles/entry.md', self::markdown('Second', ['date' => '2026-01-01']));
        $this->buildSite($root);

        $this->assertStringContainsString('Second', (string) file_get_contents($root.'/dist/index.html'));
        $this->assertSame([], glob($root.'/.verse/transactions/*') ?: []);
        $this->assertSame([], glob($root.'/.verse/previous/*') ?: []);
        $this->assertSame([], glob($root.'/.verse/staging/*') ?: []);
        $this->assertNotNull(OutputTransaction::markerId($root.'/dist'));
    }

    #[Test]
    public function a_symlinked_journal_temporary_file_is_replaced_and_its_target_left_alone(): void
    {
        $root = $this->makeSite(self::tiny());
        $outside = $this->makeSite(['victim.txt' => 'untouched']);
        mkdir($root.'/.verse/transactions', 0775, true);
        symlink($outside.'/victim.txt', $root.'/.verse/transactions/'.sha1($root.'/dist').'.json.tmp');
        // a dangling link must not be followed either
        $dangling = $root.'/.verse/transactions/other.json.tmp';
        symlink($outside.'/not-created.txt', $dangling);

        $this->buildSite($root);

        $this->assertSame('untouched', file_get_contents($outside.'/victim.txt'));
        $this->assertFileDoesNotExist($outside.'/not-created.txt');
        $this->assertFileExists($root.'/dist/index.html');
        $this->assertSame([], glob($root.'/.verse/transactions/'.sha1($root.'/dist').'.json*') ?: []);
    }

    #[Test]
    public function a_failure_between_the_two_renames_restores_the_previous_output(): void
    {
        $root = $this->makeSite(self::tiny());
        $this->buildSite($root);
        $before = self::snapshot($root.'/dist');

        file_put_contents($root.'/content/articles/entry.md', self::markdown('Changed', ['date' => '2026-01-01']));
        $fs = new FailingFilesystem(static fn (string $from, string $to): bool => str_ends_with($to, '/dist') && str_contains($from, '/staging/'));

        $this->assertFilesystemError(fn () => $this->buildSite($root, fs: $fs), 'could not publish', 'previous output was restored');

        $this->assertSame($before, self::snapshot($root.'/dist'));
        $this->assertSame([], glob($root.'/.verse/transactions/*') ?: []);
        $this->assertSame([], glob($root.'/.verse/previous/*') ?: []);
        $this->assertSame([], glob($root.'/.verse/staging/*') ?: []);

        // the failure did not poison the next build
        $this->buildSite($root);
        $this->assertStringContainsString('Changed', (string) file_get_contents($root.'/dist/index.html'));
    }

    #[Test]
    public function a_failed_restore_keeps_the_journal_and_the_next_build_recovers(): void
    {
        $root = $this->makeSite(self::tiny());
        $this->buildSite($root);
        $before = self::snapshot($root.'/dist');

        file_put_contents($root.'/content/articles/entry.md', self::markdown('Changed', ['date' => '2026-01-01']));
        $fs = new FailingFilesystem(static fn (string $from, string $to): bool => str_ends_with($to, '/dist') && (str_contains($from, '/staging/') || str_contains($from, '/previous/')));

        $this->assertFilesystemError(fn () => $this->buildSite($root, fs: $fs), 'could not publish', 'could not restore', '/.verse/previous/');

        $this->assertDirectoryDoesNotExist($root.'/dist');
        $this->assertCount(1, glob($root.'/.verse/transactions/*.json') ?: []);
        $this->assertCount(1, glob($root.'/.verse/previous/*') ?: []);

        $result = $this->buildSite($root);

        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('restored the previous output', $result->warnings[0]);
        $this->assertStringContainsString('Changed', (string) file_get_contents($root.'/dist/index.html'));
        $this->assertNotSame($before['.verse-output'], (string) file_get_contents($root.'/dist/.verse-output'));
        $this->assertSame([], glob($root.'/.verse/transactions/*') ?: []);
        $this->assertSame([], glob($root.'/.verse/previous/*') ?: []);
    }

    #[Test]
    public function a_failure_before_promotion_leaves_the_last_good_output_and_no_staging(): void
    {
        $root = $this->makeSite(self::tiny());
        $this->buildSite($root);
        $before = self::snapshot($root.'/dist');

        file_put_contents($root.'/content/bad.md', "no front matter\n");

        try {
            $this->buildSite($root);
            $this->fail('Expected an error');
        } catch (ContentException $exception) {
            $this->assertStringContainsString('content/bad.md', $exception->getMessage());
        }

        $this->assertSame($before, self::snapshot($root.'/dist'));
        $this->assertSame([], glob($root.'/.verse/staging/*') ?: []);
    }

    #[Test]
    public function a_concurrent_build_is_refused(): void
    {
        $root = $this->makeSite(self::tiny());
        mkdir($root.'/.verse');
        $handle = fopen($root.'/.verse/lock', 'c');
        $this->assertNotFalse($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        try {
            $this->assertFilesystemError(fn () => $this->buildSite($root), 'another Verse build is running');
            $this->assertDirectoryDoesNotExist($root.'/dist');
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->buildSite($root);
        $this->assertFileExists($root.'/dist/index.html');
    }

    // --- recovery table -----------------------------------------------------------------------------

    /**
     * @return array{OutputTransaction, string} the transaction and the site root
     */
    private function recoveryFixture(bool $outputExists, ?string $outputMarker, bool $previousExists, bool $stagingExists): array
    {
        $root = $this->makeSite();
        $output = $root.'/dist';
        $previous = $root.'/.verse/previous/'.self::ID;
        $staging = $root.'/.verse/staging/'.self::ID;

        $files = [];
        if ($outputExists) {
            $files['dist/page.txt'] = 'output';
            if ($outputMarker !== null) {
                $files['dist/.verse-output'] = json_encode(['id' => $outputMarker, 'verse' => 'x'], JSON_THROW_ON_ERROR);
            }
        }
        if ($previousExists) {
            $files['.verse/previous/'.self::ID.'/page.txt'] = 'previous';
            $files['.verse/previous/'.self::ID.'/.verse-output'] = json_encode(['id' => '20250101-000000-deadbeef'], JSON_THROW_ON_ERROR);
        }
        if ($stagingExists) {
            $files['.verse/staging/'.self::ID.'/page.txt'] = 'staging';
        }
        $files['.verse/transactions/'.sha1($output).'.json'] = json_encode(
            ['id' => self::ID, 'output' => $output, 'previous' => $previous, 'staging' => $staging],
            JSON_THROW_ON_ERROR,
        );
        $this->writeFiles($root, $files);

        return [new OutputTransaction($root, $output, new LocalFilesystem), $root];
    }

    #[Test]
    public function recovery_row_promotion_completed_deletes_the_previous_copy(): void
    {
        [$transaction, $root] = $this->recoveryFixture(true, self::ID, true, false);
        $warnings = new Warnings;

        $transaction->recover($warnings);

        $this->assertSame('output', file_get_contents($root.'/dist/page.txt'));
        $this->assertDirectoryDoesNotExist($root.'/.verse/previous/'.self::ID);
        $this->assertSame([], glob($root.'/.verse/transactions/*') ?: []);
        $this->assertCount(1, $warnings->all());
        $this->assertStringContainsString('already in place', $warnings->all()[0]);
    }

    #[Test]
    public function recovery_row_interrupted_before_promotion_deletes_staging_and_keeps_the_output(): void
    {
        [$transaction, $root] = $this->recoveryFixture(true, '20250101-000000-deadbeef', false, true);
        $warnings = new Warnings;

        $transaction->recover($warnings);

        $this->assertSame('output', file_get_contents($root.'/dist/page.txt'));
        $this->assertDirectoryDoesNotExist($root.'/.verse/staging/'.self::ID);
        $this->assertSame([], glob($root.'/.verse/transactions/*') ?: []);
        $this->assertCount(1, $warnings->all());
        $this->assertStringContainsString('never put in place', $warnings->all()[0]);
    }

    #[Test]
    public function recovery_row_interrupted_before_promotion_never_touches_a_leftover_previous_copy(): void
    {
        [$transaction, $root] = $this->recoveryFixture(true, null, true, true);
        $warnings = new Warnings;

        $transaction->recover($warnings);

        $this->assertSame('output', file_get_contents($root.'/dist/page.txt'));
        $this->assertSame('previous', file_get_contents($root.'/.verse/previous/'.self::ID.'/page.txt'));
        $this->assertStringContainsString('leftover copy', $warnings->all()[0]);
    }

    #[Test]
    public function recovery_row_interrupted_between_the_renames_restores_the_previous_output(): void
    {
        [$transaction, $root] = $this->recoveryFixture(false, null, true, true);
        $warnings = new Warnings;

        $transaction->recover($warnings);

        $this->assertSame('previous', file_get_contents($root.'/dist/page.txt'));
        $this->assertDirectoryDoesNotExist($root.'/.verse/previous/'.self::ID);
        $this->assertDirectoryDoesNotExist($root.'/.verse/staging/'.self::ID);
        $this->assertSame([], glob($root.'/.verse/transactions/*') ?: []);
        $this->assertStringContainsString('restored the previous output', $warnings->all()[0]);
    }

    #[Test]
    public function recovery_row_nothing_to_restore_deletes_staging_and_warns(): void
    {
        [$transaction, $root] = $this->recoveryFixture(false, null, false, true);
        $warnings = new Warnings;

        $transaction->recover($warnings);

        $this->assertDirectoryDoesNotExist($root.'/dist');
        $this->assertDirectoryDoesNotExist($root.'/.verse/staging/'.self::ID);
        $this->assertSame([], glob($root.'/.verse/transactions/*') ?: []);
        $this->assertCount(1, $warnings->all());
        $this->assertStringContainsString('no previous output to restore', $warnings->all()[0]);
    }

    #[Test]
    public function a_failed_restoration_keeps_the_journal_and_fails(): void
    {
        [, $root] = $this->recoveryFixture(false, null, true, true);
        $transaction = new OutputTransaction($root, $root.'/dist', new FailingFilesystem(static fn (): bool => true));

        $this->assertFilesystemError(fn () => $transaction->recover(new Warnings), 'could not restore', '/.verse/previous/');

        $this->assertCount(1, glob($root.'/.verse/transactions/*.json') ?: []);
        $this->assertSame('previous', file_get_contents($root.'/.verse/previous/'.self::ID.'/page.txt'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidJournals(): array
    {
        $root = '{root}';
        $valid = ['id' => self::ID, 'output' => $root.'/dist', 'previous' => $root.'/.verse/previous/'.self::ID, 'staging' => $root.'/.verse/staging/'.self::ID];

        return [
            'not json' => ['{nope'],
            'not an object' => ['"text"'],
            'missing key' => [json_encode(array_diff_key($valid, ['staging' => 1]), JSON_THROW_ON_ERROR)],
            'non-string value' => [json_encode(['id' => 5] + $valid, JSON_THROW_ON_ERROR)],
            'malformed id' => [json_encode(['id' => '../x'] + $valid, JSON_THROW_ON_ERROR)],
            'output outside the site' => [json_encode(['output' => '/etc'] + $valid, JSON_THROW_ON_ERROR)],
            'output with dots' => [json_encode(['output' => $root.'/dist/../content'] + $valid, JSON_THROW_ON_ERROR)],
            'staging elsewhere' => [json_encode(['staging' => $root.'/content'] + $valid, JSON_THROW_ON_ERROR)],
            'previous elsewhere' => [json_encode(['previous' => $root.'/public'] + $valid, JSON_THROW_ON_ERROR)],
            'previous of another id' => [json_encode(['previous' => $root.'/.verse/previous/20250101-000000-deadbeef'] + $valid, JSON_THROW_ON_ERROR)],
        ];
    }

    #[Test]
    #[DataProvider('invalidJournals')]
    public function an_invalid_journal_is_refused_and_nothing_is_deleted(string $journal): void
    {
        $root = $this->makeSite([
            'dist/page.txt' => 'output',
            '.verse/previous/'.self::ID.'/page.txt' => 'previous',
            '.verse/staging/'.self::ID.'/page.txt' => 'staging',
            'content/keep.md' => 'content',
        ]);
        $journal = str_replace('{root}', $root, $journal);
        $name = json_decode($journal, true);
        $file = $root.'/.verse/transactions/'.sha1(is_array($name) && is_string($name['output'] ?? null) ? $name['output'] : $root.'/dist').'.json';
        $this->writeFiles($root, ['.verse/transactions/'.basename($file) => $journal]);
        $before = self::snapshot($root);

        $this->assertFilesystemError(
            fn () => (new OutputTransaction($root, $root.'/dist', new LocalFilesystem))->recover(new Warnings),
            'transaction journal',
            basename($file),
            'nothing was deleted',
        );

        $this->assertSame($before, self::snapshot($root));
    }

    #[Test]
    public function a_journal_whose_name_does_not_match_its_output_is_refused(): void
    {
        $root = $this->makeSite(['dist/page.txt' => 'output']);
        $this->writeFiles($root, ['.verse/transactions/wrong.json' => json_encode([
            'id' => self::ID, 'output' => $root.'/dist', 'previous' => $root.'/.verse/previous/'.self::ID, 'staging' => $root.'/.verse/staging/'.self::ID,
        ], JSON_THROW_ON_ERROR)]);

        $this->assertFilesystemError(
            fn () => (new OutputTransaction($root, $root.'/dist', new LocalFilesystem))->recover(new Warnings),
            'wrong.json',
            'file name does not match',
        );
    }

    #[Test]
    public function every_journal_is_validated_before_any_is_acted_on(): void
    {
        $root = $this->makeSite([
            '.verse/staging/'.self::ID.'/page.txt' => 'staging',
            '.verse/transactions/zzz.json' => '{nope',
        ]);
        $this->writeFiles($root, ['.verse/transactions/'.sha1($root.'/dist').'.json' => json_encode([
            'id' => self::ID, 'output' => $root.'/dist', 'previous' => $root.'/.verse/previous/'.self::ID, 'staging' => $root.'/.verse/staging/'.self::ID,
        ], JSON_THROW_ON_ERROR)]);

        $this->assertFilesystemError(fn () => (new OutputTransaction($root, $root.'/dist', new LocalFilesystem))->recover(new Warnings), 'zzz.json');

        $this->assertFileExists($root.'/.verse/staging/'.self::ID.'/page.txt');
    }

    // --- path guard ---------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function unsafeOutputs(): array
    {
        return [
            'inside content' => ['{root}/content/out', 'overlaps the content/'],
            'content inside the output' => ['{root}/content', 'overlaps the content/'],
            'inside public' => ['{root}/public/x', 'overlaps the public/'],
            'the site root' => ['{root}', 'contains the site'],
            'a parent of the site' => ['{root}/..', 'contains the site'],
            'dotted path into content' => ['{root}/./content/new-output', 'overlaps the content/'],
            'dot-dot path into content' => ['{root}/dist/../content/x', 'overlaps the content/'],
            'doubled slashes into public' => ['{root}//public//x', 'overlaps the public/'],
            'an ancestor of the site' => ['/', 'contains the site'],
            'inside the state directory' => ['{root}/.verse/out', 'private state'],
            'relative path' => ['dist', 'absolute path'],
        ];
    }

    #[Test]
    #[DataProvider('unsafeOutputs')]
    public function unsafe_output_locations_are_rejected(string $output, string $message): void
    {
        $root = $this->makeSite(self::tiny() + ['public/a.txt' => 'a']);
        $context = BuildContext::create($root, (new ConfigLoader)->load($root, requireUrl: false), outputDir: str_replace('{root}', $root, $output));

        $this->assertFilesystemError(fn () => PathGuard::assertSafe($context), $message);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function managedPaths(): array
    {
        return array_map(
            static fn (string $path): array => [$path],
            array_combine(
                ['.verse/cache', '.verse/cache/views', '.verse/cache/views-preview', '.verse/staging', '.verse/previous', '.verse/transactions', '.verse/preview'],
                ['.verse/cache', '.verse/cache/views', '.verse/cache/views-preview', '.verse/staging', '.verse/previous', '.verse/transactions', '.verse/preview'],
            ),
        );
    }

    #[Test]
    #[DataProvider('managedPaths')]
    public function a_symlinked_managed_subpath_is_refused_and_its_target_untouched(string $managed): void
    {
        $root = $this->makeSite(self::tiny());
        $target = $this->makeSite(['precious.txt' => 'keep', 'views/also.txt' => 'keep']);
        mkdir(dirname($root.'/'.$managed), 0775, true);
        symlink($target, $root.'/'.$managed);
        $before = self::snapshot($target);

        $this->assertFilesystemError(fn () => $this->buildSite($root), 'symbolic link', $managed);

        $this->assertSame($before, self::snapshot($target));
        $this->assertDirectoryDoesNotExist($root.'/dist');
    }

    #[Test]
    public function an_output_reached_through_a_symlinked_ancestor_is_compared_by_its_real_location(): void
    {
        $root = $this->makeSite(self::tiny() + ['content/keep.md' => self::markdown('Keep')]);
        symlink($root.'/content', $root.'/shortcut');
        $context = BuildContext::create($root, (new ConfigLoader)->load($root, requireUrl: false), outputDir: $root.'/shortcut/out');

        $this->assertFilesystemError(fn () => PathGuard::assertSafe($context), 'overlaps the content/');
    }

    #[Test]
    public function a_dotted_output_path_is_rejected_by_a_build_and_nothing_is_written(): void
    {
        $root = $this->makeSite(self::tiny());
        $config = (new ConfigLoader)->load($root, requireUrl: true);
        $context = BuildContext::create($root, $config, outputDir: $root.'/./content/new-output');

        $this->assertFilesystemError(
            fn () => (new Builder(ThemeRegistry::bundled(), new LocalFilesystem))->build($context),
            'overlaps the content/',
        );
        $this->assertDirectoryDoesNotExist($root.'/content/new-output');
    }

    #[Test]
    public function an_equivalent_dotted_output_path_builds_into_the_normalised_location(): void
    {
        $root = $this->makeSite(self::tiny());
        $config = (new ConfigLoader)->load($root, requireUrl: true);
        $context = BuildContext::create($root, $config, outputDir: $root.'/./out/../out2');

        $result = (new Builder(ThemeRegistry::bundled(), new LocalFilesystem))->build($context);

        $this->assertSame($root.'/out2', $result->outputDir);
        $this->assertFileExists($root.'/out2/index.html');
    }

    #[Test]
    public function preview_output_must_live_inside_the_preview_directory(): void
    {
        $root = $this->makeSite(self::tiny());
        $config = (new ConfigLoader)->load($root, requireUrl: false);

        PathGuard::assertSafe(BuildContext::create($root, $config, BuildMode::Preview, outputDir: $root.'/.verse/preview/public'));
        $this->assertFilesystemError(
            fn () => PathGuard::assertSafe(BuildContext::create($root, $config, BuildMode::Preview, outputDir: $root.'/dist')),
            'inside .verse/preview/',
        );
        $this->assertFilesystemError(
            fn () => PathGuard::assertSafe(BuildContext::create($root, $config, BuildMode::Preview, outputDir: $root.'/.verse/preview')),
            'inside .verse/preview/',
        );
    }

    #[Test]
    public function a_custom_output_directory_is_built_and_managed(): void
    {
        $root = $this->makeSite(self::tiny());
        $config = (new ConfigLoader)->load($root, requireUrl: true);
        $out = $root.'/build-output';
        $context = BuildContext::create($root, $config, outputDir: $out);

        (new Builder(ThemeRegistry::bundled(), new LocalFilesystem))->build($context);
        (new Builder(ThemeRegistry::bundled(), new LocalFilesystem))->build($context);

        $this->assertFileExists($out.'/index.html');
        $this->assertNotNull(OutputTransaction::markerId($out));
    }
}
