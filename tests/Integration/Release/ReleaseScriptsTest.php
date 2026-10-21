<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Release;

use Astro\Verse\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * The release scripts in scripts/release and the release workflow that calls them. Nothing here
 * reaches the network or GitHub: runs, Packagist and `gh` are replaced by stubs the scripts accept
 * through RELEASE_RUNS_FETCH_COMMAND, PACKAGIST_URL, RELEASE_GH_COMMAND and PATH.
 */
final class ReleaseScriptsTest extends TestCase
{
    // ------------------------------------------------------------------ tag-version.sh

    #[Test]
    #[DataProvider('validTags')]
    public function it_validates_tags_and_detects_pre_releases(string $tag, bool $pre): void
    {
        $process = self::script('tag-version.sh', [$tag]);

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString('prerelease='.($pre ? 'true' : 'false'), $process->getOutput());
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function validTags(): array
    {
        return [
            'v0.1.0' => ['v0.1.0', false],
            'v10.20.30' => ['v10.20.30', false],
            'v0.1.0-rc.1' => ['v0.1.0-rc.1', true],
            'v0.1.0-rc1' => ['v0.1.0-rc1', true],
            'v0.1.0-RC2' => ['v0.1.0-RC2', true],
            'v0.1.0-alpha1' => ['v0.1.0-alpha1', true],
            'v0.1.0-beta.3' => ['v0.1.0-beta.3', true],
        ];
    }

    #[Test]
    #[DataProvider('invalidTags')]
    public function it_refuses_invalid_tags(string $tag): void
    {
        $process = self::script('tag-version.sh', [$tag]);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Invalid release tag', $process->getErrorOutput());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidTags(): array
    {
        $tags = ['', 'v1.2', '0.1.0', 'vx', 'v0.1.0;echo', 'xv1.0.0', 'v1.0.0x', "v1.0.0\n", "v1.0.0\nv2.0.0", 'v1.0.0-foo', 'v1.0.0-dev', 'v1.0.0-patch1', 'v1.0.0-rc.', 'v1.0.0-rc', 'v1.0.0-beta', 'v1.0.0-alpha', 'v1.0.0-rc.1.2', 'v1.0.0-', 'v1.0.0.1', 'v1.0.0+build'];
        $cases = [];
        foreach ($tags as $tag) {
            $cases[(string) json_encode($tag)] = [$tag];
        }

        return $cases;
    }

    // ------------------------------------------------------------------ changelog-entry.sh

    #[Test]
    public function it_extracts_only_the_unreleased_changelog_body(): void
    {
        $file = $this->temp().'/CHANGELOG.md';
        file_put_contents($file, "# Changelog\n\nIntro.\n\n## Unreleased\n\nFirst.\n\nSecond.\n\n## v0.0.9 - 2026-10-10\n\nOld.\n");

        $process = self::script('changelog-entry.sh', ['v0.1.0', $file]);

        $this->assertSame(0, $process->getExitCode(), self::combined($process));
        $this->assertSame("First.\n\nSecond.\n", $process->getOutput());
    }

    #[Test]
    #[DataProvider('badChangelogEntries')]
    public function it_refuses_absent_empty_and_similarly_named_unreleased_sections(string $contents, string $message): void
    {
        $file = $this->temp().'/CHANGELOG.md';
        file_put_contents($file, $contents);

        $process = self::script('changelog-entry.sh', ['v0.1.0', $file]);

        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertStringContainsString($message, $process->getErrorOutput());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badChangelogEntries(): array
    {
        $absent = 'CHANGELOG.md has no "## Unreleased" heading; add one above the latest release with the notes for v0.1.0.';
        $empty = 'CHANGELOG.md has no release notes under "## Unreleased"; add the notes for v0.1.0 there.';

        return [
            'only released versions' => ["## v0.0.9\n\nOld.\n", $absent],
            'heading for the tag' => ["## v0.1.0\n\nNot here.\n", $absent],
            'bracketed heading' => ["## [Unreleased]\n\nNo.\n", $absent],
            'lower case' => ["## unreleased\n\nNo.\n", $absent],
            'trailing text' => ["## Unreleased soon\n\nNo.\n", $absent],
            'third level' => ["### Unreleased\n\nNo.\n", $absent],
            'empty section' => ["## Unreleased\n\n## v0.0.9\n\nOld.\n", $empty],
            'blank lines only' => ["## Unreleased\n\n  \n\n", $empty],
        ];
    }

    #[Test]
    public function it_reads_a_crlf_changelog(): void
    {
        $file = $this->temp().'/CHANGELOG.md';
        file_put_contents($file, "## Unreleased\r\n\r\nFirst.\r\n\r\n### Added\r\n\r\n- Second.\r\n\r\n## v0.0.9\r\n\r\nOld.\r\n");

        $process = self::script('changelog-entry.sh', ['v0.1.0', $file]);

        $this->assertSame(0, $process->getExitCode());
        $this->assertSame("First.\n\n### Added\n\n- Second.\n", $process->getOutput());
        $this->assertStringNotContainsString("\r", $process->getOutput());
    }

    #[Test]
    public function it_names_the_tag_when_the_changelog_file_is_missing(): void
    {
        $process = self::script('changelog-entry.sh', ['v0.1.0', $this->temp().'/none.md']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('v0.1.0 needs release notes under "## Unreleased"', $process->getErrorOutput());
    }

    // ------------------------------------------------------------------ changelog-release.sh

    #[Test]
    public function it_dates_the_unreleased_heading_and_opens_a_new_one(): void
    {
        $file = $this->temp().'/CHANGELOG.md';
        file_put_contents($file, "# Changelog\n\n## Unreleased\n\n- New.\n\n## v0.0.9 - 2026-10-10\n\n- Old.\n");

        $process = self::script('changelog-release.sh', ['v0.1.0', '2026-10-21', $file]);

        $this->assertSame(0, $process->getExitCode(), self::combined($process));
        $this->assertSame("# Changelog\n\n## Unreleased\n\n## v0.1.0 - 2026-10-21\n\n- New.\n\n## v0.0.9 - 2026-10-10\n\n- Old.\n", self::read($file));

        $empty = self::script('changelog-entry.sh', ['v0.1.1', $file]);
        $this->assertSame(1, $empty->getExitCode());
        $this->assertStringContainsString('no release notes under "## Unreleased"', $empty->getErrorOutput());
    }

    #[Test]
    public function it_keeps_crlf_line_endings_when_dating_the_changelog(): void
    {
        $file = $this->temp().'/CHANGELOG.md';
        file_put_contents($file, "## Unreleased\r\n\r\n- New.\r\n");

        $this->assertSame(0, self::script('changelog-release.sh', ['v0.1.0', '2026-10-21', $file])->getExitCode());
        $this->assertSame("## Unreleased\r\n\r\n## v0.1.0 - 2026-10-21\r\n\r\n- New.\r\n", self::read($file));
    }

    #[Test]
    public function it_refuses_to_date_a_changelog_without_an_unreleased_heading_or_with_bad_input(): void
    {
        $file = $this->temp().'/CHANGELOG.md';
        file_put_contents($file, "## v0.0.9\n\n- Old.\n");

        $absent = self::script('changelog-release.sh', ['v0.1.0', '2026-10-21', $file]);
        $this->assertSame(1, $absent->getExitCode());
        $this->assertStringContainsString('no "## Unreleased" heading', $absent->getErrorOutput());
        $this->assertSame("## v0.0.9\n\n- Old.\n", self::read($file));

        $date = self::script('changelog-release.sh', ['v0.1.0', '21.10.2026', $file]);
        $this->assertSame(1, $date->getExitCode());
        $this->assertStringContainsString('Invalid release date: 21.10.2026', $date->getErrorOutput());

        $tag = self::script('changelog-release.sh', ['0.1.0', '2026-10-21', $file]);
        $this->assertSame(1, $tag->getExitCode());
        $this->assertStringContainsString('Invalid release tag', $tag->getErrorOutput());
    }

    // ------------------------------------------------------------------ publish-changelog.sh

    #[Test]
    public function it_commits_the_dated_changelog_to_main_when_main_still_has_the_tagged_changelog(): void
    {
        [$directory, $file, $env] = $this->publishSetup("## Unreleased\n\n- New.\n");
        $blob = self::git($directory, 'hash-object', $file);
        file_put_contents("$directory/main-sha", $blob);

        $process = self::script('publish-changelog.sh', ['v0.1.0', $file], $env, $directory);

        $this->assertSame(0, $process->getExitCode(), self::combined($process));
        $this->assertStringContainsString('CHANGELOG.md on main now has "## v0.1.0 - 2026-10-21".', $process->getOutput());
        $put = self::lines("$directory/put");
        $this->assertSame(['api', '--method', 'PUT', 'repos/o/r/contents/CHANGELOG.md'], array_slice($put, 0, 4));
        $this->assertContains('message=Add v0.1.0 to CHANGELOG.md', $put);
        $this->assertContains('branch=main', $put);
        $this->assertContains("sha=$blob", $put);
        $this->assertSame("## Unreleased\n\n## v0.1.0 - 2026-10-21\n\n- New.\n", base64_decode(self::read("$directory/content"), true));
        $this->assertSame("## Unreleased\n\n- New.\n", self::read($file));
    }

    #[Test]
    public function it_refuses_to_date_the_changelog_when_main_changed_after_the_tag(): void
    {
        [$directory, $file, $env] = $this->publishSetup("## Unreleased\n\n- New.\n");
        file_put_contents("$directory/main-sha", 'other');
        file_put_contents("$directory/main", "## Unreleased\n\n- Later.\n- New.\n");

        $process = self::script('publish-changelog.sh', ['v0.1.0', $file], $env, $directory);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('CHANGELOG.md on main changed after v0.1.0 was tagged', $process->getErrorOutput());
        $this->assertStringContainsString('under a "## v0.1.0 - 2026-10-21" heading', $process->getErrorOutput());
        $this->assertFileDoesNotExist("$directory/put");
    }

    #[Test]
    #[DataProvider('releasedHeadings')]
    public function it_leaves_a_changelog_that_main_already_dated(string $main, int $code): void
    {
        [$directory, $file, $env] = $this->publishSetup("## Unreleased\n\n- New.\n");
        file_put_contents("$directory/main-sha", 'other');
        file_put_contents("$directory/main", $main);

        $process = self::script('publish-changelog.sh', ['v0.1.0', $file], $env, $directory);

        $this->assertSame($code, $process->getExitCode(), self::combined($process));
        $this->assertFileDoesNotExist("$directory/put");
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function releasedHeadings(): array
    {
        return [
            'dated' => ["## Unreleased\n\n## v0.1.0 - 2026-10-21\n\n- New.\n", 0],
            'undated' => ["## v0.1.0\n\n- New.\n", 0],
            'crlf' => ["## v0.1.0 - 2026-10-21\r\n\r\n- New.\r\n", 0],
            'similar version' => ["## v0x1y0\n\n- New.\n", 1],
            'longer version' => ["## v0.1.01\n\n- New.\n", 1],
            'pre-release of it' => ["## v0.1.0-rc.1\n\n- New.\n", 1],
        ];
    }

    #[Test]
    public function it_leaves_the_changelog_alone_for_a_pre_release(): void
    {
        [$directory, $file, $env] = $this->publishSetup("## Unreleased\n\n- New.\n");

        $process = self::script('publish-changelog.sh', ['v0.1.0-rc.1', $file], $env, $directory);

        $this->assertSame(0, $process->getExitCode(), self::combined($process));
        $this->assertStringContainsString('v0.1.0-rc.1 is a pre-release', $process->getOutput());
        $this->assertFileDoesNotExist("$directory/calls");
    }

    #[Test]
    public function it_refuses_an_invalid_tag_or_a_missing_repository_when_publishing_the_changelog(): void
    {
        [$directory, $file, $env] = $this->publishSetup("## Unreleased\n\n- New.\n");

        $tag = self::script('publish-changelog.sh', ['v0.1', $file], $env, $directory);
        $this->assertSame(1, $tag->getExitCode());
        $this->assertStringContainsString('Invalid release tag', $tag->getErrorOutput());

        $repo = self::script('publish-changelog.sh', ['v0.1.0', $file], ['GH_REPO' => ''] + $env, $directory);
        $this->assertSame(1, $repo->getExitCode());
        $this->assertStringContainsString('GH_REPO is required', $repo->getErrorOutput());
        $this->assertFileDoesNotExist("$directory/calls");
    }

    // ------------------------------------------------------------------ on-main.sh

    #[Test]
    public function it_checks_commits_on_main_directly(): void
    {
        $directory = $this->repo();
        $main = self::git($directory, 'rev-parse', 'HEAD');
        $this->assertSame(0, self::script('on-main.sh', [$main, 'main'], [], $directory)->getExitCode());

        self::git($directory, 'checkout', '--quiet', '-b', 'feature');
        self::git($directory, 'commit', '--quiet', '--allow-empty', '-m', 'feature');
        $process = self::script('on-main.sh', ['HEAD', 'main'], [], $directory);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('not an ancestor', $process->getErrorOutput());

        $missing = self::script('on-main.sh', [$main, 'origin/main'], [], $directory);
        $this->assertSame(1, $missing->getExitCode());
        $this->assertStringContainsString('Cannot find origin/main', $missing->getErrorOutput());
    }

    // ------------------------------------------------------------------ runs-green.sh

    #[Test]
    #[DataProvider('workflowRunListings')]
    public function it_classifies_workflow_runs_with_messages(string $input, int $code, string $message): void
    {
        $process = self::script('runs-green.sh', ['tests.yml', 'abc', 'main'], [], null, $input);

        $this->assertSame($code, $process->getExitCode());
        $this->assertStringContainsString($message, self::combined($process));
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function workflowRunListings(): array
    {
        return [
            'success' => [self::runJson('completed', 'success'), 0, 'successful'],
            'failure' => [self::runJson('completed', 'failure'), 1, 'without success'],
            'cancelled' => [self::runJson('completed', 'cancelled'), 1, 'cancelled'],
            'in progress' => [self::runJson('in_progress'), 2, 'in_progress'],
            'none' => ['{"workflow_runs":[]}', 3, 'no push run'],
            'other sha' => [self::runJson('completed', 'success', 'other'), 3, 'no push run'],
            'other branch' => ['{"workflow_runs":[{"head_sha":"abc","head_branch":"feature","event":"push","status":"completed","conclusion":"success"}]}', 3, 'no push run'],
            'pull request run' => ['{"workflow_runs":[{"head_sha":"abc","head_branch":"main","event":"pull_request","status":"completed","conclusion":"success"}]}', 3, 'no push run'],
            'invalid json' => ['no', 1, 'invalid runs JSON'],
            'success then a later run in progress' => [self::runsJson([self::workflowRun(1, 'completed', 'success'), self::workflowRun(2, 'in_progress')]), 2, 'in_progress'],
            'failure then a later success' => [self::runsJson([self::workflowRun(1, 'completed', 'failure'), self::workflowRun(2, 'completed', 'success')]), 0, 'successful'],
            'success then a later failure' => [self::runsJson([self::workflowRun(1, 'completed', 'success'), self::workflowRun(2, 'completed', 'failure')]), 1, 'failure'],
            'success then a later cancelled run' => [self::runsJson([self::workflowRun(2, 'completed', 'cancelled'), self::workflowRun(1, 'completed', 'success')]), 1, 'cancelled'],
            'same run, higher attempt succeeds' => [self::runsJson([self::workflowRun(1, 'completed', 'failure', 1), self::workflowRun(1, 'completed', 'success', 2)]), 0, 'successful'],
            'same run, higher attempt fails' => [self::runsJson([self::workflowRun(1, 'completed', 'success', 1), self::workflowRun(1, 'completed', 'failure', 2)]), 1, 'failure'],
            'equal runs, one failure' => [self::runsJson([self::workflowRun(1, 'completed', 'success'), self::workflowRun(1, 'completed', 'failure')]), 1, 'failure'],
            'equal runs, one in progress' => [self::runsJson([self::workflowRun(1, 'completed', 'success'), self::workflowRun(1, 'queued')]), 2, 'queued'],
            'equal runs, both succeed' => [self::runsJson([self::workflowRun(1, 'completed', 'success'), self::workflowRun(1, 'completed', 'success')]), 0, 'successful'],
        ];
    }

    // ------------------------------------------------------------------ wait-for-runs.sh

    /**
     * @param  list<string>  $responses
     */
    #[Test]
    #[DataProvider('waitSequences')]
    public function it_waits_for_runs_and_reports_the_outcome(array $responses, int $code, string $message): void
    {
        $directory = $this->temp();
        $fetcher = self::sequenceFetcher($directory, $responses);

        $process = self::script('wait-for-runs.sh', ['tests.yml', 'abc'], self::waitEnv($directory, $fetcher, ['RELEASE_RUNS_TIMEOUT_SECONDS' => '2']));

        $this->assertSame($code, $process->getExitCode());
        $this->assertStringContainsString($message, self::combined($process));
    }

    /**
     * @return array<string, array{list<string>, int, string}>
     */
    public static function waitSequences(): array
    {
        return [
            'success after progress' => [[self::runJson('in_progress'), self::runJson('completed', 'success')], 0, 'successful'],
            'failure stops' => [[self::runJson('completed', 'failure')], 1, 'without success'],
        ];
    }

    #[Test]
    public function it_gives_up_after_the_deadline_naming_the_workflow(): void
    {
        $directory = $this->temp();
        $fetcher = self::sequenceFetcher($directory, array_fill(0, 50, self::runJson('in_progress')));

        $process = self::script('wait-for-runs.sh', ['tests.yml', 'abc'], self::waitEnv($directory, $fetcher, ['RELEASE_RUNS_TIMEOUT_SECONDS' => '0']));

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('tests.yml did not finish', $process->getErrorOutput());
        $this->assertSame("tests.yml abc\n", self::read("$directory/log"));
    }

    #[Test]
    public function it_honours_an_already_passed_shared_deadline(): void
    {
        $directory = $this->temp();
        $fetcher = self::sequenceFetcher($directory, array_fill(0, 50, self::runJson('in_progress')));

        $process = self::script('wait-for-runs.sh', ['tests.yml', 'abc'], self::waitEnv($directory, $fetcher, ['RELEASE_RUNS_DEADLINE' => '1', 'RELEASE_RUNS_TIMEOUT_SECONDS' => '999']));

        $this->assertSame(1, $process->getExitCode());
        $this->assertSame(1, substr_count(self::read("$directory/log"), "\n"));
    }

    #[Test]
    public function it_tolerates_a_run_that_does_not_exist_yet_only_for_the_grace_period(): void
    {
        $directory = $this->temp();
        $fetcher = self::sequenceFetcher($directory, ['{"workflow_runs":[]}', self::runJson('completed', 'success')]);
        $process = self::script('wait-for-runs.sh', ['tests.yml', 'abc'], self::waitEnv($directory, $fetcher, ['RELEASE_RUNS_GRACE_SECONDS' => '30']));
        $this->assertSame(0, $process->getExitCode());

        $directory = $this->temp();
        $fetcher = self::sequenceFetcher($directory, array_fill(0, 5, '{"workflow_runs":[]}'));
        $process = self::script('wait-for-runs.sh', ['tests.yml', 'abc'], self::waitEnv($directory, $fetcher));
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('never created a push run of tests.yml', $process->getErrorOutput());
    }

    #[Test]
    public function it_reports_a_failed_run_fetch_with_its_own_message(): void
    {
        $directory = $this->temp();
        $fetcher = self::executable("$directory/fetch", "printf 'unavailable\\n' >&2\nexit 1");

        $process = self::script('wait-for-runs.sh', ['tests.yml', 'abc'], ['RELEASE_RUNS_FETCH_COMMAND' => $fetcher, 'RELEASE_RUNS_TIMEOUT_SECONDS' => '0']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('unavailable', $process->getErrorOutput());
        $this->assertStringContainsString('Could not query tests.yml', $process->getErrorOutput());
    }

    #[Test]
    public function it_keeps_fetch_stderr_out_of_the_runs_json(): void
    {
        $directory = $this->temp();
        $json = self::runJson('completed', 'success');
        $fetcher = self::executable("$directory/fetch", "printf 'a warning\\n' >&2\nprintf '%s' '$json'");

        $process = self::script('wait-for-runs.sh', ['tests.yml', 'abc'], ['RELEASE_RUNS_FETCH_COMMAND' => $fetcher, 'RELEASE_RUNS_TIMEOUT_SECONDS' => '0']);

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString('a warning', $process->getErrorOutput());
    }

    // ------------------------------------------------------------------ wait-for-checks.sh

    #[Test]
    public function it_requires_both_tests_yml_and_install_yml(): void
    {
        $directory = $this->temp();
        $fetcher = self::executable("$directory/fetch", 'printf "%s\n" "$1" >> "$RUNS_LOG"; printf \'{"workflow_runs":[{"head_sha":"%s","head_branch":"main","event":"push","status":"completed","conclusion":"success"}]}\' "$2"');
        $process = self::script('wait-for-checks.sh', ['abc'], ['RELEASE_RUNS_FETCH_COMMAND' => $fetcher, 'RUNS_LOG' => "$directory/log", 'RELEASE_RUNS_TIMEOUT_SECONDS' => '0']);
        $this->assertSame(0, $process->getExitCode());
        $this->assertSame("tests.yml\ninstall.yml\n", self::read("$directory/log"));

        $failing = self::executable("$directory/fail", 'if [ "$1" = install.yml ]; then printf \'{"workflow_runs":[]}\'; else printf \'{"workflow_runs":[{"head_sha":"%s","head_branch":"main","event":"push","status":"completed","conclusion":"success"}]}\' "$2"; fi');
        $process = self::script('wait-for-checks.sh', ['abc'], ['RELEASE_RUNS_FETCH_COMMAND' => $failing, 'RELEASE_RUNS_TIMEOUT_SECONDS' => '0', 'RELEASE_RUNS_GRACE_SECONDS' => '0']);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('install.yml', $process->getErrorOutput());
    }

    #[Test]
    public function it_shares_one_deadline_between_the_two_workflows(): void
    {
        $directory = $this->temp();
        $fetcher = self::executable("$directory/fetch", 'printf "%s\n" "$1" >> "$RUNS_LOG"; printf \'{"workflow_runs":[{"head_sha":"%s","head_branch":"main","event":"push","status":"queued"}]}\' "$2"');

        $process = self::script('wait-for-checks.sh', ['abc'], ['RELEASE_RUNS_FETCH_COMMAND' => $fetcher, 'RUNS_LOG' => "$directory/log", 'RELEASE_RUNS_TIMEOUT_SECONDS' => '0']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertSame("tests.yml\n", self::read("$directory/log"));
    }

    // ------------------------------------------------------------------ packagist-wait.sh

    #[Test]
    #[DataProvider('packagistVersions')]
    public function it_reads_the_packagist_metadata_with_exact_versions(string $tag, string $listed, int $code): void
    {
        $file = $this->temp().'/metadata';
        file_put_contents($file, json_encode(['packages' => ['astrophp/verse' => [['version' => $listed]]]]));

        $process = self::script('packagist-wait.sh', [$tag], ['PACKAGIST_URL' => "file://$file", 'PACKAGIST_TIMEOUT_SECONDS' => '0', 'PACKAGIST_SLEEP_SECONDS' => '0']);

        $this->assertSame($code, $process->getExitCode());
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function packagistVersions(): array
    {
        return [
            'same tag' => ['v0.1.0', 'v0.1.0', 0],
            'four part version' => ['v0.1.0', 'v0.1.0.1', 1],
            'dev branch' => ['v0.1.0', 'dev-main', 1],
            'only a release candidate' => ['v0.1.0', 'v0.1.0-rc.1', 1],
            'release candidate tag, stable listed' => ['v0.1.0-rc.1', 'v0.1.0', 1],
            'release candidate tag and listing' => ['v0.1.0-rc.1', 'v0.1.0-rc.1', 0],
        ];
    }

    #[Test]
    public function it_handles_unavailable_and_normalized_packagist_metadata(): void
    {
        $bad = self::script('packagist-wait.sh', ['v0.1.0'], ['PACKAGIST_URL' => 'file:///missing-release-metadata', 'PACKAGIST_TIMEOUT_SECONDS' => '0']);
        $this->assertSame(1, $bad->getExitCode());
        $this->assertStringContainsString('does not list v0.1.0', $bad->getErrorOutput());

        $file = $this->temp().'/metadata';
        file_put_contents($file, json_encode(['packages' => ['astrophp/verse' => [['version_normalized' => '0.1.0.0']]]]));
        $this->assertSame(0, self::script('packagist-wait.sh', ['v0.1.0'], ['PACKAGIST_URL' => "file://$file", 'PACKAGIST_TIMEOUT_SECONDS' => '0'])->getExitCode());
    }

    #[Test]
    #[DataProvider('packagistFailures')]
    public function it_says_why_packagist_did_not_list_the_version(string $body, string $url, string $reason): void
    {
        $directory = $this->temp();
        if ($body !== '') {
            file_put_contents("$directory/metadata", $body);
        }

        $process = self::script('packagist-wait.sh', ['v0.1.0'], ['PACKAGIST_URL' => $url === '' ? "file://$directory/metadata" : $url, 'PACKAGIST_TIMEOUT_SECONDS' => '0']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString($reason, $process->getErrorOutput());
        $this->assertStringContainsString('Last reason: '.$reason, $process->getErrorOutput());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function packagistFailures(): array
    {
        return [
            'missing package' => ['', 'file:///missing-release-metadata', 'the package is not on Packagist yet (HTTP 404)'],
            'invalid json' => ['<html>', '', 'the Packagist response is not valid JSON'],
            'version not listed' => ['{"packages":{"astrophp/verse":[{"version":"v0.0.9"}]}}', '', 'the Packagist metadata does not list v0.1.0'],
        ];
    }

    #[Test]
    public function it_polls_the_verse_cli_package_by_default(): void
    {
        $this->assertStringContainsString(
            'https://repo.packagist.org/p2/astrophp/verse.json',
            self::read(self::root().'/scripts/release/packagist-wait.sh'),
        );
    }

    #[Test]
    public function it_polls_packagist_with_a_cache_busting_query_and_retries_until_listed(): void
    {
        $directory = $this->temp();
        mkdir("$directory/bin");
        $metadata = json_encode(['packages' => ['astrophp/verse' => [['version' => 'v0.1.0']]]]);
        self::executable("$directory/bin/curl", 'n=0; [ -f "$CURL_STATE" ] && n=$(cat "$CURL_STATE"); n=$((n+1)); printf "%s" "$n" > "$CURL_STATE"; for last; do :; done; printf "%s\n" "$last" >> "$CURL_LOG"; if [ "$n" -lt 2 ]; then printf "{}"; else printf \'%s\' \''.$metadata.'\'; fi');

        $process = self::script('packagist-wait.sh', ['v0.1.0'], ['PATH' => "$directory/bin:".(string) getenv('PATH'), 'CURL_STATE' => "$directory/n", 'CURL_LOG' => "$directory/urls", 'PACKAGIST_URL' => 'https://repo.packagist.org/p2/astrophp/verse.json', 'PACKAGIST_TIMEOUT_SECONDS' => '5', 'PACKAGIST_SLEEP_SECONDS' => '0']);

        $urls = self::lines("$directory/urls");
        $this->assertSame(0, $process->getExitCode(), self::combined($process));
        $this->assertCount(2, $urls);
        $this->assertMatchesRegularExpression('#^https://repo\.packagist\.org/p2/astrophp/verse\.json\?cb=\d+$#', $urls[0]);
    }

    // ------------------------------------------------------------------ encode-notes.sh, decode-notes.sh

    #[Test]
    public function it_encodes_and_decodes_multi_line_notes_unchanged(): void
    {
        $notes = "Intro `code` and \$(touch /tmp/never) \"quoted\" 'single' \\ back\n\n- Zażółć gęślą jaźń 日本語 🚀\n- \$HOME \${x}\n";
        $directory = $this->temp();
        file_put_contents("$directory/CHANGELOG.md", "## Unreleased\n\n$notes\n## v0.0.9\n\nOld.\n");

        $entry = self::script('changelog-entry.sh', ['v0.1.0', "$directory/CHANGELOG.md"]);
        $this->assertSame($notes, $entry->getOutput());

        $encoded = self::script('encode-notes.sh', [], [], null, $entry->getOutput());
        $this->assertSame(0, $encoded->getExitCode());
        $this->assertMatchesRegularExpression('/^notes=[A-Za-z0-9+\/=]+\n$/', $encoded->getOutput());

        $decoded = self::script('decode-notes.sh', ["$directory/out.md"], ['NOTES' => substr(trim($encoded->getOutput()), 6)]);
        $this->assertSame(0, $decoded->getExitCode());
        $this->assertSame($notes, self::read("$directory/out.md"));
    }

    #[Test]
    public function it_refuses_empty_notes_when_decoding(): void
    {
        $process = self::script('decode-notes.sh', [$this->temp().'/out.md'], ['NOTES' => '']);

        $this->assertSame(1, $process->getExitCode());
    }

    // ------------------------------------------------------------------ release-exists.sh, create-release.sh

    #[Test]
    #[DataProvider('releaseListings')]
    public function it_detects_an_existing_release_including_drafts(string $listing, int $code): void
    {
        $process = self::script('release-exists.sh', ['v0.1.0'], [], null, $listing);

        $this->assertSame($code, $process->getExitCode());
        if ($code === 0) {
            $this->assertStringContainsString('already exists for v0.1.0', $process->getErrorOutput());
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function releaseListings(): array
    {
        return [
            'present' => ["v0.0.9\nv0.1.0\n", 0],
            'only one' => ["v0.1.0\n", 0],
            'absent' => ["v0.0.9\nv0.1.0-rc.1\nv0.1.01\n", 1],
            'empty' => ['', 1],
        ];
    }

    #[Test]
    #[DataProvider('releaseTags')]
    public function it_creates_a_release_with_a_verified_tag_and_pre_release_only_for_suffixed_tags(string $tag, bool $pre): void
    {
        $directory = $this->temp();
        file_put_contents("$directory/notes.md", 'Notes');
        $gh = self::executable("$directory/gh", 'printf "%s\n" "$@" > "$GH_LOG"');

        $process = self::script('create-release.sh', [$tag, "$directory/notes.md"], ['RELEASE_GH_COMMAND' => $gh, 'GH_LOG' => "$directory/args"]);

        $args = self::lines("$directory/args");
        $this->assertSame(0, $process->getExitCode());
        $this->assertSame(['release', 'create', $tag], array_slice($args, 0, 3));
        $this->assertContains('--verify-tag', $args);
        $this->assertContains('--notes-file', $args);
        $this->assertSame($pre, in_array('--prerelease', $args, true));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function releaseTags(): array
    {
        return [
            'stable' => ['v0.1.0', false],
            'release candidate' => ['v0.1.0-rc.1', true],
            'beta' => ['v0.1.0-beta2', true],
        ];
    }

    #[Test]
    public function it_refuses_to_create_a_release_for_an_invalid_tag(): void
    {
        $directory = $this->temp();
        file_put_contents("$directory/notes.md", 'Notes');
        $gh = self::executable("$directory/gh", 'touch "$GH_LOG"');

        $process = self::script('create-release.sh', ['v0.1.0-foo', "$directory/notes.md"], ['RELEASE_GH_COMMAND' => $gh, 'GH_LOG' => "$directory/args"]);

        $this->assertSame(1, $process->getExitCode());
        $this->assertFileDoesNotExist("$directory/args");
    }

    // ------------------------------------------------------------------ dist-clean.sh

    #[Test]
    public function it_fails_the_build_check_on_changed_or_untracked_files(): void
    {
        $directory = $this->repo(['themes/paper/dist/app.css' => 'a', 'themes/paper/theme.yaml' => 'b', 'src/other.txt' => 'c']);
        $this->assertSame(0, self::script('dist-clean.sh', [], [], $directory)->getExitCode());

        file_put_contents("$directory/themes/paper/dist/app.css", 'changed');
        $modified = self::script('dist-clean.sh', [], [], $directory);
        $this->assertSame(1, $modified->getExitCode());
        $this->assertStringContainsString('themes/paper/dist/app.css', $modified->getErrorOutput());

        self::git($directory, 'checkout', '--', 'themes/paper/dist/app.css');
        file_put_contents("$directory/themes/paper/dist/new.css", 'x');
        $untracked = self::script('dist-clean.sh', [], [], $directory);
        $this->assertSame(1, $untracked->getExitCode());
        $this->assertStringContainsString('?? themes/paper/dist/new.css', $untracked->getErrorOutput());

        unlink("$directory/themes/paper/dist/new.css");
        file_put_contents("$directory/themes/paper/theme.yaml", 'changed');
        $this->assertSame(1, self::script('dist-clean.sh', [], [], $directory)->getExitCode());
    }

    #[Test]
    public function it_checks_only_the_themes_directory_by_default(): void
    {
        $directory = $this->repo(['themes/paper/dist/app.css' => 'a', 'src/other.txt' => 'c']);
        file_put_contents("$directory/src/other.txt", 'changed');
        file_put_contents("$directory/stray.txt", 'x');

        $this->assertSame(0, self::script('dist-clean.sh', [], [], $directory)->getExitCode());
        $this->assertSame(1, self::script('dist-clean.sh', ['src'], [], $directory)->getExitCode());
    }

    // ------------------------------------------------------------------ preflight.sh

    #[Test]
    public function it_prints_the_checked_commit_and_tag_commands_in_preflight(): void
    {
        [, $clone, $sha] = $this->preflightSetup();

        $process = self::script('preflight.sh', ['v0.1.0'], ['PREFLIGHT_SKIP_RUNS' => '1'], $clone);

        $this->assertSame(0, $process->getExitCode(), self::combined($process));
        $this->assertStringContainsString("Checked commit $sha", $process->getOutput());
        $this->assertStringContainsString("git tag v0.1.0 $sha && git push origin v0.1.0", $process->getOutput());
        $this->assertStringContainsString('Nothing has checked that the committed theme build', $process->getOutput());
        $this->assertSame('', self::git($clone, 'tag'));
    }

    #[Test]
    public function it_checks_origin_main_even_when_local_main_is_behind(): void
    {
        [$remote, $clone] = $this->preflightSetup();
        self::git($remote, 'commit', '--quiet', '--allow-empty', '-m', 'next');
        $head = self::git($remote, 'rev-parse', 'HEAD');

        $process = self::script('preflight.sh', ['v0.1.0'], ['PREFLIGHT_SKIP_RUNS' => '1'], $clone);

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString("git tag v0.1.0 $head", $process->getOutput());
    }

    #[Test]
    public function it_refuses_an_invalid_tag_in_preflight(): void
    {
        [, $clone] = $this->preflightSetup();

        $process = self::script('preflight.sh', ['v0.1.0-foo'], ['PREFLIGHT_SKIP_RUNS' => '1'], $clone);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Invalid release tag', $process->getErrorOutput());
    }

    #[Test]
    public function it_refuses_an_existing_local_preflight_tag(): void
    {
        [, $clone] = $this->preflightSetup();
        self::git($clone, 'tag', 'v0.1.0');

        $process = self::script('preflight.sh', ['v0.1.0'], ['PREFLIGHT_SKIP_RUNS' => '1'], $clone);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('exists locally', $process->getErrorOutput());
    }

    #[Test]
    public function it_refuses_a_tag_that_exists_on_the_remote(): void
    {
        [$remote, $clone] = $this->preflightSetup();
        self::git($remote, 'tag', 'v0.1.0');

        $process = self::script('preflight.sh', ['v0.1.0'], ['PREFLIGHT_SKIP_RUNS' => '1'], $clone);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('exists on origin', $process->getErrorOutput());
    }

    #[Test]
    public function it_refuses_a_commit_outside_main_in_preflight(): void
    {
        [$remote, $clone] = $this->preflightSetup();
        self::git($remote, 'checkout', '--quiet', '-b', 'feature');
        self::git($remote, 'commit', '--quiet', '--allow-empty', '-m', 'feature');
        self::git($clone, 'fetch', '--quiet', 'origin', 'feature');

        $process = self::script('preflight.sh', ['v0.1.0'], ['PREFLIGHT_SKIP_RUNS' => '1', 'PREFLIGHT_COMMIT' => 'origin/feature'], $clone);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('not an ancestor', $process->getErrorOutput());
    }

    #[Test]
    public function it_reads_the_changelog_at_the_checked_commit_not_the_working_tree(): void
    {
        [, $clone] = $this->preflightSetup("## Unreleased\n\n## v0.0.1\n\nOld.\n");
        file_put_contents("$clone/CHANGELOG.md", "## Unreleased\n\nOnly local.\n");
        $process = self::script('preflight.sh', ['v0.1.0'], ['PREFLIGHT_SKIP_RUNS' => '1'], $clone);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('no release notes under "## Unreleased"', $process->getErrorOutput());
        $this->assertStringNotContainsString('git tag', $process->getOutput());

        [, $clone] = $this->preflightSetup();
        file_put_contents("$clone/CHANGELOG.md", "## v0.0.1\n\nOld.\n");
        $this->assertSame(0, self::script('preflight.sh', ['v0.1.0'], ['PREFLIGHT_SKIP_RUNS' => '1'], $clone)->getExitCode());
    }

    #[Test]
    public function it_refuses_a_missing_changelog_at_the_commit_in_preflight(): void
    {
        $remote = $this->repo();
        $clone = $this->cloneOf($remote);

        $process = self::script('preflight.sh', ['v0.1.0'], ['PREFLIGHT_SKIP_RUNS' => '1'], $clone);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('does not exist at commit', $process->getErrorOutput());
    }

    #[Test]
    public function it_checks_the_workflow_runs_in_preflight_without_waiting(): void
    {
        [, $clone] = $this->preflightSetup();
        $directory = $this->temp();
        mkdir("$directory/bin");
        self::executable("$directory/bin/gh", 'exit 0');
        $env = ['PATH' => "$directory/bin:".(string) getenv('PATH'), 'GH_REPO' => 'o/r', 'RELEASE_RUNS_SLEEP_SECONDS' => '30'];

        $green = self::executable("$directory/green", 'printf \'{"workflow_runs":[{"head_sha":"%s","head_branch":"main","event":"push","status":"completed","conclusion":"success"}]}\' "$2"');
        $passed = self::script('preflight.sh', ['v0.1.0'], $env + ['RELEASE_RUNS_FETCH_COMMAND' => $green], $clone);
        $this->assertSame(0, $passed->getExitCode(), self::combined($passed));
        $this->assertStringContainsString('covered by the green tests run', $passed->getOutput());
        $this->assertStringNotContainsString('Nothing has checked', $passed->getOutput());

        $red = self::executable("$directory/red", 'printf \'{"workflow_runs":[{"head_sha":"%s","head_branch":"main","event":"push","status":"completed","conclusion":"failure"}]}\' "$2"');
        $failed = self::script('preflight.sh', ['v0.1.0'], $env + ['RELEASE_RUNS_FETCH_COMMAND' => $red], $clone);
        $this->assertSame(1, $failed->getExitCode());
        $this->assertStringNotContainsString('git tag', $failed->getOutput());

        $started = microtime(true);
        $busy = self::executable("$directory/busy", 'printf \'{"workflow_runs":[{"head_sha":"%s","head_branch":"main","event":"push","status":"in_progress"}]}\' "$2"');
        $pending = self::script('preflight.sh', ['v0.1.0'], $env + ['RELEASE_RUNS_FETCH_COMMAND' => $busy], $clone);
        $this->assertSame(1, $pending->getExitCode());
        $this->assertStringContainsString('in_progress', $pending->getErrorOutput());
        $this->assertLessThan(20.0, microtime(true) - $started);
    }

    #[Test]
    public function it_requires_gh_for_the_run_check_in_preflight(): void
    {
        [, $clone] = $this->preflightSetup();
        $directory = $this->temp();
        mkdir("$directory/bin");
        foreach (['git', 'bash', 'php', 'mktemp', 'rm', 'dirname', 'awk', 'sed', 'env'] as $tool) {
            $path = trim((new Process(['sh', '-c', "command -v $tool"]))->mustRun()->getOutput());
            symlink($path, "$directory/bin/$tool");
        }

        $process = self::script('preflight.sh', ['v0.1.0'], ['PATH' => "$directory/bin"], $clone);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('gh is required', $process->getErrorOutput());
    }

    // ------------------------------------------------------------------ .github/workflows/release.yml

    #[Test]
    public function the_workflow_declares_exactly_the_trigger_and_permissions(): void
    {
        $yaml = self::workflow();
        $jobs = self::jobs();

        $this->assertSame([], $yaml['permissions']);
        $this->assertSame(['push' => ['tags' => ['v*']]], $yaml['on']);
        $this->assertSame('bash', $yaml['defaults']['run']['shell']);
        $this->assertSame(['contents' => 'read', 'actions' => 'read'], $jobs['verify']['permissions']);
        $this->assertSame(['contents' => 'write'], $jobs['release']['permissions']);
        $this->assertSame(['contents' => 'read'], $jobs['packagist']['permissions']);
        $this->assertSame(['contents' => 'write'], $jobs['changelog']['permissions']);

        $writes = 0;
        foreach ($jobs as $job) {
            foreach ($job['permissions'] as $permission) {
                $writes += $permission === 'write' ? 1 : 0;
            }
        }
        $this->assertSame(2, $writes);
    }

    #[Test]
    public function the_workflow_is_free_of_pushes_secrets_and_unpinned_third_party_actions(): void
    {
        $text = self::read(self::root().'/.github/workflows/release.yml');

        $this->assertDoesNotMatchRegularExpression('/git push|git tag|--force|npm publish|workflow_dispatch|pull_request_target/', $text);
        $this->assertDoesNotMatchRegularExpression('/secrets\\./', $text);

        preg_match_all('/uses: ([^\\s]+)/', $text, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $action) {
            $this->assertTrue(
                str_starts_with($action, 'actions/') || preg_match('/@[a-f0-9]{40}$/', $action) === 1,
                "$action is a third-party action that is not pinned to a commit",
            );
        }
    }

    #[Test]
    public function the_workflow_never_expands_an_expression_inside_a_run_script(): void
    {
        $count = 0;
        foreach (self::jobs() as $job) {
            foreach ($job['steps'] as $step) {
                if (isset($step['run'])) {
                    $count++;
                    $this->assertStringNotContainsString('${{', $step['run']);
                }
            }
        }

        $this->assertGreaterThan(5, $count);
    }

    #[Test]
    public function the_workflow_does_not_keep_the_checkout_credential(): void
    {
        $checkouts = 0;
        foreach (self::jobs() as $job) {
            foreach ($job['steps'] as $step) {
                if (str_starts_with($step['uses'] ?? '', 'actions/checkout@')) {
                    $checkouts++;
                    $this->assertFalse($step['with']['persist-credentials']);
                }
            }
        }

        $this->assertSame(4, $checkouts);
    }

    #[Test]
    public function the_workflow_wires_the_verify_release_packagist_and_changelog_steps_to_their_scripts(): void
    {
        $jobs = self::jobs();

        $verify = self::runScript($jobs['verify']);
        foreach (['scripts/release/tag-version.sh "$TAG"', 'scripts/release/on-main.sh "$sha"', 'scripts/release/wait-for-checks.sh "$SHA"', 'npm run build', 'scripts/release/dist-clean.sh', 'changelog-entry.sh "$TAG"', 'encode-notes.sh'] as $expected) {
            $this->assertStringContainsString($expected, $verify);
        }
        $release = self::runScript($jobs['release']);
        foreach (['release-exists.sh "$TAG"', 'decode-notes.sh', 'create-release.sh "$TAG"'] as $expected) {
            $this->assertStringContainsString($expected, $release);
        }
        $this->assertStringContainsString('packagist-wait.sh "$TAG"', self::runScript($jobs['packagist']));
        $this->assertStringContainsString('publish-changelog.sh "$TAG"', self::runScript($jobs['changelog']));

        $this->assertSame('verify', $jobs['release']['needs'] ?? null);
        $this->assertSame('release', $jobs['packagist']['needs'] ?? null);
        $this->assertSame('release', $jobs['changelog']['needs'] ?? null);
        $this->assertGreaterThanOrEqual(35, $jobs['verify']['timeout-minutes']);
        $this->assertGreaterThanOrEqual(25, $jobs['packagist']['timeout-minutes']);
    }

    #[Test]
    public function the_workflow_verifies_the_committed_theme_build(): void
    {
        $names = array_column(self::jobs()['verify']['steps'], 'name');

        $this->assertContains('Verify committed theme build', $names);
    }

    // ------------------------------------------------------------------ helpers

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * A new empty temporary directory, removed after the test.
     */
    private function temp(): string
    {
        return $this->makeSite();
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     */
    private static function script(string $script, array $args = [], array $env = [], ?string $cwd = null, ?string $input = null): Process
    {
        $process = new Process([self::root().'/scripts/release/'.$script, ...$args], $cwd ?? self::root(), $env === [] ? null : $env);
        if ($input !== null) {
            $process->setInput($input);
        }
        $process->run();

        return $process;
    }

    private static function combined(Process $process): string
    {
        return $process->getOutput().$process->getErrorOutput();
    }

    private static function git(string $directory, string ...$args): string
    {
        return trim((new Process(['git', ...$args], $directory))->mustRun()->getOutput());
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Cannot read $path.");
        }

        return $contents;
    }

    /**
     * @return list<string>
     */
    private static function lines(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException("Cannot read $path.");
        }

        return $lines;
    }

    /**
     * A git repository on `main` with one commit holding `$files` (path => contents).
     *
     * @param  array<string, string>  $files
     */
    private function repo(array $files = []): string
    {
        $directory = $this->temp();
        self::git($directory, 'init', '--quiet', '--initial-branch=main');
        self::git($directory, 'config', 'user.email', 't@e');
        self::git($directory, 'config', 'user.name', 'T');
        $this->writeFiles($directory, $files);
        foreach (array_keys($files) as $name) {
            self::git($directory, 'add', $name);
        }
        self::git($directory, 'commit', '--quiet', '--allow-empty', '-m', 'main');

        return $directory;
    }

    private function cloneOf(string $remote): string
    {
        $directory = $this->temp();
        (new Process(['git', 'clone', '--quiet', $remote, $directory.'/c']))->mustRun();
        self::git("$directory/c", 'config', 'user.email', 't@e');
        self::git("$directory/c", 'config', 'user.name', 'T');

        return "$directory/c";
    }

    /**
     * A remote whose main holds a changelog, and a clone of it.
     *
     * @return array{string, string, string} the remote, the clone and the remote's commit
     */
    private function preflightSetup(string $changelog = "## Unreleased\n\nNotes.\n"): array
    {
        $remote = $this->repo(['CHANGELOG.md' => $changelog]);

        return [$remote, $this->cloneOf($remote), self::git($remote, 'rev-parse', 'HEAD')];
    }

    /**
     * A directory with the tagged changelog and a fake gh: `--jq` prints `main-sha`, the raw read
     * prints `main`, and a PUT writes its arguments to `put` and the uploaded content to `content`.
     *
     * @return array{string, string, array<string, string>} the directory, the changelog and the env
     */
    private function publishSetup(string $changelog): array
    {
        $directory = $this->temp();
        file_put_contents("$directory/CHANGELOG.md", $changelog);
        $gh = self::executable("$directory/gh", <<<'SH'
            printf '%s\n' "$*" >> "$FAKE_DIR/calls"
            case " $* " in
                *" --method PUT "*)
                    printf '%s\n' "$@" > "$FAKE_DIR/put"
                    for arg in "$@"; do
                        case "$arg" in content=@*) cp "${arg#content=@}" "$FAKE_DIR/content";; esac
                    done
                    ;;
                *" --jq "*) cat "$FAKE_DIR/main-sha" ;;
                *) cat "$FAKE_DIR/main" ;;
            esac
            SH);

        return [$directory, "$directory/CHANGELOG.md", ['RELEASE_GH_COMMAND' => $gh, 'GH_REPO' => 'o/r', 'RELEASE_DATE' => '2026-10-21', 'FAKE_DIR' => $directory, 'PATH' => (string) getenv('PATH')]];
    }

    private static function executable(string $path, string $body): string
    {
        file_put_contents($path, "#!/bin/sh\n$body\n");
        chmod($path, 0755);

        return $path;
    }

    private static function runJson(string $status, ?string $conclusion = null, string $sha = 'abc'): string
    {
        return self::runsJson([['head_sha' => $sha, 'head_branch' => 'main', 'event' => 'push', 'status' => $status, 'conclusion' => $conclusion]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function workflowRun(int $number, string $status, ?string $conclusion = null, int $attempt = 1): array
    {
        return ['head_sha' => 'abc', 'head_branch' => 'main', 'event' => 'push', 'run_number' => $number, 'run_attempt' => $attempt, 'created_at' => '2026-10-10T10:00:00Z', 'status' => $status, 'conclusion' => $conclusion];
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private static function runsJson(array $runs): string
    {
        return json_encode(['workflow_runs' => $runs], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<string>  $responses  one runs listing per fetch
     */
    private static function sequenceFetcher(string $directory, array $responses): string
    {
        file_put_contents("$directory/source", implode("\n", $responses));

        return self::executable("$directory/fetch", 'count=0; [ -f "$RUNS_STATE" ] && count=$(cat "$RUNS_STATE"); count=$((count+1)); printf "%s" "$count" > "$RUNS_STATE"; printf "%s %s\n" "$1" "$2" >> "$RUNS_LOG"; sed -n "${count}p" "$RUNS_SOURCE"');
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private static function waitEnv(string $directory, string $fetcher, array $extra = []): array
    {
        return array_merge(['RELEASE_RUNS_FETCH_COMMAND' => $fetcher, 'RUNS_STATE' => "$directory/state", 'RUNS_LOG' => "$directory/log", 'RUNS_SOURCE' => "$directory/source", 'RELEASE_RUNS_TIMEOUT_SECONDS' => '5', 'RELEASE_RUNS_SLEEP_SECONDS' => '0', 'RELEASE_RUNS_GRACE_SECONDS' => '0'], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private static function workflow(): array
    {
        $workflow = Yaml::parse(self::read(self::root().'/.github/workflows/release.yml'));
        assert(is_array($workflow));

        /** @var array<string, mixed> $workflow */
        return $workflow;
    }

    /**
     * @return array<string, array{permissions: array<string, string>, steps: list<array<string, mixed>>, needs?: string, 'timeout-minutes': int}>
     */
    private static function jobs(): array
    {
        /** @var array<string, array{permissions: array<string, string>, steps: list<array<string, mixed>>, needs?: string, 'timeout-minutes': int}> $jobs */
        $jobs = self::workflow()['jobs'];

        return $jobs;
    }

    /**
     * The run scripts of a job's steps, joined.
     *
     * @param  array{steps: list<array<string, mixed>>}  $job
     */
    private static function runScript(array $job): string
    {
        return implode("\n", array_map(static fn (array $step): string => is_string($step['run'] ?? null) ? $step['run'] : '', $job['steps']));
    }
}
