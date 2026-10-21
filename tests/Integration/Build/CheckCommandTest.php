<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Build;

use Astro\Verse\Console\Application;
use Astro\Verse\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\ApplicationTester;

final class CheckCommandTest extends TestCase
{
    private const string ID = '20261020-120000-0a1b2c3d';

    private string $previousCwd = '';

    protected function setUp(): void
    {
        $this->previousCwd = (string) getcwd();
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        parent::tearDown();
    }

    private function runCommand(string $command, string $root): ApplicationTester
    {
        chdir($root);
        $application = new Application;
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => $command], ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function runCheck(string $root): ApplicationTester
    {
        return $this->runCommand('check', $root);
    }

    private function pageCount(ApplicationTester $tester): int
    {
        if (preg_match('~^(?:Built|Checked) (\d+) pages?~', $tester->getDisplay(), $matches) !== 1) {
            $this->fail('No page count in: '.$tester->getDisplay());
        }

        return (int) $matches[1];
    }

    /**
     * An interrupted promotion: a journal that names a staging and a previous folder of the site.
     *
     * @return array<string, string>
     */
    private function journalFiles(string $root): array
    {
        return [
            '.verse/previous/'.self::ID.'/index.html' => 'older output',
            '.verse/previous/'.self::ID.'/.verse-output' => json_encode(['id' => '20250101-000000-deadbeef'], JSON_THROW_ON_ERROR),
            '.verse/staging/'.self::ID.'/index.html' => 'half written',
            '.verse/transactions/'.sha1($root.'/dist').'.json' => json_encode([
                'id' => self::ID,
                'output' => $root.'/dist',
                'previous' => $root.'/.verse/previous/'.self::ID,
                'staging' => $root.'/.verse/staging/'.self::ID,
            ], JSON_THROW_ON_ERROR),
        ];
    }

    #[Test]
    public function it_checks_the_site_and_creates_nothing_in_it(): void
    {
        $root = $this->fixtureSite();
        $before = self::snapshot($root);

        $tester = $this->runCheck($root);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertMatchesRegularExpression('~^Checked \d+ pages in \d+\.\d\ds\. dist/ was not changed\.\n$~', $tester->getDisplay());
        $this->assertSame('', $tester->getErrorOutput());
        $this->assertSame($before, self::snapshot($root));
        $this->assertDirectoryDoesNotExist($root.'/dist');
        $this->assertDirectoryDoesNotExist($root.'/.verse');
    }

    #[Test]
    public function it_counts_the_same_pages_as_a_build_so_drafts_and_scheduled_articles_stay_out(): void
    {
        $root = $this->fixtureSite('notes', [
            'content/articles/long-ago.md' => self::markdown('Long ago', ['date' => '2000-01-01']),
            'content/articles/far-future.md' => self::markdown('Far future', ['date' => '2999-01-01']),
            'content/articles/unfinished.md' => self::markdown('Unfinished', ['date' => '2000-01-02', 'draft' => 'true']),
        ]);

        $checked = $this->pageCount($this->runCheck($root));
        $built = $this->pageCount($this->runCommand('build', $root));

        $this->assertSame($built, $checked);
        $this->assertFileExists($root.'/dist/articles/long-ago/index.html');
        $this->assertFileDoesNotExist($root.'/dist/articles/far-future/index.html');
        $this->assertFileDoesNotExist($root.'/dist/articles/unfinished/index.html');
    }

    #[Test]
    public function a_successful_artifact_is_left_untouched(): void
    {
        $root = $this->fixtureSite();
        $this->assertSame(0, $this->runCommand('build', $root)->getStatusCode());
        $before = self::snapshot($root);
        $this->assertArrayHasKey('dist/.verse-output', $before);

        $tester = $this->runCheck($root);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame($before, self::snapshot($root));
    }

    #[Test]
    public function a_content_error_names_the_file_and_leaves_the_site_untouched(): void
    {
        $root = $this->fixtureSite();
        $this->assertSame(0, $this->runCommand('build', $root)->getStatusCode());
        $this->writeFiles($root, ['content/articles/broken.md' => "---\ntitle: Broken\ndate: soon\n---\nText\n"]);
        $before = self::snapshot($root);

        $tester = $this->runCheck($root);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('error: content error in content/articles/broken.md, field "date"', $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
        $this->assertSame($before, self::snapshot($root));
    }

    #[Test]
    public function a_rendering_time_failure_names_the_file_and_leaves_the_site_untouched(): void
    {
        $root = $this->fixtureSite('notes', [
            'content/articles/linker.md' => self::markdown('Linker', ['date' => '2000-01-01'], "See [the draft](draft-notes.md).\n"),
        ]);
        $before = self::snapshot($root);

        $tester = $this->runCheck($root);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('error: ', $tester->getErrorOutput());
        $this->assertStringContainsString('content/articles/linker.md', $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
        $this->assertSame($before, self::snapshot($root));
        $this->assertDirectoryDoesNotExist($root.'/dist');
        $this->assertDirectoryDoesNotExist($root.'/.verse');
    }

    #[Test]
    public function warnings_are_printed_on_stderr_and_do_not_fail_the_check(): void
    {
        $root = $this->fixtureSite('notes', [
            'verse.yaml' => "title: Site\nurl: https://example.com/\nauthor: Your Name\nsocial_links:\n  github: https://github.com/your-username\n",
        ]);

        $tester = $this->runCheck($root);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('warning: verse.yaml: url uses the placeholder host "example.com"', $tester->getErrorOutput());
        $this->assertStringContainsString('Checked ', $tester->getDisplay());
        $this->assertStringNotContainsString('warning', $tester->getDisplay());
    }

    #[Test]
    public function warnings_collected_before_a_failure_are_still_printed(): void
    {
        $root = $this->fixtureSite('notes', [
            'verse.yaml' => "title: Site\nurl: https://example.com/\n",
            'content/articles/broken.md' => "---\ntitle: Broken\ndate: soon\n---\nText\n",
        ]);

        $tester = $this->runCheck($root);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('warning: verse.yaml: url uses the placeholder host "example.com"', $tester->getErrorOutput());
        $this->assertStringContainsString('error: content error in content/articles/broken.md', $tester->getErrorOutput());
    }

    #[Test]
    public function an_unmanaged_dist_directory_fails_like_a_build_and_is_untouched(): void
    {
        $root = $this->fixtureSite('notes', ['dist/mine.txt' => 'mine']);

        $built = $this->runCommand('build', $root);
        $before = self::snapshot($root);

        $tester = $this->runCheck($root);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('error: filesystem error', $tester->getErrorOutput());
        $this->assertStringContainsString('was not created by Verse', $tester->getErrorOutput());
        $this->assertSame($built->getErrorOutput(), $tester->getErrorOutput());
        $this->assertSame($before, self::snapshot($root));
        $this->assertSame(['mine.txt' => 'mine'], self::snapshot($root.'/dist'));
    }

    #[Test]
    public function a_missing_verse_yaml_is_a_config_error_and_nothing_is_created(): void
    {
        $root = $this->makeSite();

        $tester = $this->runCheck($root);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('error: config error', $tester->getErrorOutput());
        $this->assertStringContainsString('verse.yaml was not found', $tester->getErrorOutput());
        $this->assertSame([], self::snapshot($root));
        $this->assertDirectoryDoesNotExist($root.'/.verse');
    }

    #[Test]
    public function a_missing_url_is_a_config_error(): void
    {
        $root = $this->fixtureSite('notes', ['verse.yaml' => "title: Notes\n"]);

        $tester = $this->runCheck($root);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('error: config error in verse.yaml, field "url"', $tester->getErrorOutput());
        $this->assertDirectoryDoesNotExist($root.'/.verse');
    }

    #[Test]
    public function an_interrupted_promotion_is_warned_about_and_left_alone_until_the_next_build(): void
    {
        $root = $this->fixtureSite();
        $this->assertSame(0, $this->runCommand('build', $root)->getStatusCode());
        $this->writeFiles($root, $this->journalFiles($root));
        $before = self::snapshot($root);

        $tester = $this->runCheck($root);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('warning: .verse/transactions/ contains a build journal; a build may be running or was interrupted. Check left it alone.', $tester->getErrorOutput());
        $this->assertSame($before, self::snapshot($root));

        $build = $this->runCommand('build', $root);

        $this->assertSame(0, $build->getStatusCode());
        $this->assertStringContainsString('warning: recovered an interrupted build of '.$root.'/dist', $build->getErrorOutput());
        $this->assertSame([], glob($root.'/.verse/transactions/*') ?: []);
        $this->assertDirectoryDoesNotExist($root.'/.verse/staging/'.self::ID);
    }

    #[Test]
    public function a_malformed_journal_is_warned_about_and_left_alone(): void
    {
        $root = $this->fixtureSite('notes', ['.verse/transactions/whatever.json' => 'not json']);
        $before = self::snapshot($root);

        $tester = $this->runCheck($root);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('warning: .verse/transactions/ contains a build journal', $tester->getErrorOutput());
        $this->assertSame($before, self::snapshot($root));
    }

    #[Test]
    public function a_check_runs_while_the_build_lock_is_held(): void
    {
        $root = $this->fixtureSite();
        mkdir($root.'/.verse');
        $handle = fopen($root.'/.verse/lock', 'c');
        $this->assertNotFalse($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        $before = self::snapshot($root);

        try {
            $tester = $this->runCheck($root);
            $build = $this->runCommand('build', $root);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->assertSame(1, $build->getStatusCode(), 'the lock is really held');
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame($before, self::snapshot($root));
    }
}
