<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Build;

use Astro\Verse\Console\Application;
use Astro\Verse\Error\ThemeException;
use Astro\Verse\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\ApplicationTester;

final class BuildCommandTest extends TestCase
{
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

    private function runBuild(string $root): ApplicationTester
    {
        chdir($root);
        $application = new Application;
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'build'], ['capture_stderr_separately' => true]);

        return $tester;
    }

    #[Test]
    public function it_builds_the_site_in_the_working_directory_and_prints_a_summary(): void
    {
        $root = $this->fixtureSite();
        $tester = $this->runBuild($root);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertMatchesRegularExpression('~^Built 12 pages to dist/ in \d+\.\d\ds\.\n$~', $tester->getDisplay());
        $this->assertSame('', $tester->getErrorOutput());
        $this->assertFileExists($root.'/dist/index.html');
        $this->assertFileExists($root.'/dist/.verse-output');
    }

    #[Test]
    public function a_missing_url_is_a_config_error(): void
    {
        $root = $this->fixtureSite('notes', ['verse.yaml' => "title: Notes\n"]);
        $tester = $this->runBuild($root);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('error: config error in verse.yaml, field "url"', $tester->getErrorOutput());
        $this->assertDirectoryDoesNotExist($root.'/dist');
    }

    #[Test]
    public function a_missing_verse_yaml_is_a_config_error(): void
    {
        $tester = $this->runBuild($this->makeSite());

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('error: config error', $tester->getErrorOutput());
        $this->assertStringContainsString('verse.yaml was not found', $tester->getErrorOutput());
    }

    #[Test]
    public function a_content_error_names_the_file_and_exits_one(): void
    {
        $root = $this->fixtureSite('notes', ['content/articles/broken.md' => "---\ntitle: Broken\ndate: soon\n---\nText\n"]);
        $tester = $this->runBuild($root);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('error: content error in content/articles/broken.md, field "date"', $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
    }

    #[Test]
    public function a_theme_error_exits_one(): void
    {
        $root = $this->fixtureSite('notes', [
            'verse.yaml' => (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sites/notes/verse.yaml')."theme:\n  name: nope\n",
        ]);
        $tester = $this->runBuild($root);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('unknown theme "nope". Available themes: default.', $tester->getErrorOutput());
    }

    #[Test]
    public function warnings_are_printed_and_do_not_fail_the_build(): void
    {
        $root = $this->fixtureSite('notes', [
            'verse.yaml' => "title: Site\nurl: https://example.com/\nauthor: Your Name\nsocial_links:\n  github: https://github.com/your-username\n",
            'themes/paper/theme.yaml' => "name: x\n",
        ]);
        $tester = $this->runBuild($root);

        $this->assertSame(0, $tester->getStatusCode());
        $errors = $tester->getErrorOutput();
        $this->assertSame(4, substr_count($errors, 'warning: '));
        $this->assertStringContainsString('warning: verse.yaml: url uses the placeholder host "example.com"', $errors);
        $this->assertStringContainsString('warning: site themes are not supported in this version; the themes/ directory is ignored', $errors);
        $this->assertStringContainsString('Built ', $tester->getDisplay());
    }

    #[Test]
    public function an_unmanaged_dist_directory_exits_one_and_is_untouched(): void
    {
        $root = $this->fixtureSite('notes', ['dist/mine.txt' => 'mine']);
        $tester = $this->runBuild($root);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('error: filesystem error', $tester->getErrorOutput());
        $this->assertSame(['mine.txt' => 'mine'], self::snapshot($root.'/dist'));
    }

    #[Test]
    public function an_interrupted_promotion_is_recovered_before_a_malformed_config_is_reported(): void
    {
        $id = '20261020-120000-0a1b2c3d';
        $root = $this->makeSite(['verse.yaml' => "title: [unclosed\n"]);
        $this->writeFiles($root, [
            '.verse/previous/'.$id.'/index.html' => 'last good',
            '.verse/previous/'.$id.'/.verse-output' => json_encode(['id' => '20250101-000000-deadbeef'], JSON_THROW_ON_ERROR),
            '.verse/staging/'.$id.'/index.html' => 'half written',
            '.verse/transactions/'.sha1($root.'/dist').'.json' => json_encode([
                'id' => $id,
                'output' => $root.'/dist',
                'previous' => $root.'/.verse/previous/'.$id,
                'staging' => $root.'/.verse/staging/'.$id,
            ], JSON_THROW_ON_ERROR),
        ]);

        $tester = $this->runBuild($root);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('error: config error in verse.yaml', $tester->getErrorOutput());
        $this->assertStringContainsString('warning: recovered an interrupted build of '.$root.'/dist: restored the previous output.', $tester->getErrorOutput());
        $this->assertSame('last good', file_get_contents($root.'/dist/index.html'));
        $this->assertSame([], glob($root.'/.verse/transactions/*') ?: []);
        $this->assertDirectoryDoesNotExist($root.'/.verse/staging/'.$id);
    }

    #[Test]
    public function an_interrupted_promotion_is_recovered_even_when_verse_yaml_is_missing(): void
    {
        $id = '20261020-120000-0a1b2c3d';
        $root = $this->makeSite();
        $this->writeFiles($root, [
            '.verse/previous/'.$id.'/index.html' => 'last good',
            '.verse/previous/'.$id.'/.verse-output' => json_encode(['id' => '20250101-000000-deadbeef'], JSON_THROW_ON_ERROR),
            '.verse/transactions/'.sha1($root.'/dist').'.json' => json_encode([
                'id' => $id,
                'output' => $root.'/dist',
                'previous' => $root.'/.verse/previous/'.$id,
                'staging' => $root.'/.verse/staging/'.$id,
            ], JSON_THROW_ON_ERROR),
        ]);

        $tester = $this->runBuild($root);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('error: config error', $tester->getErrorOutput());
        $this->assertStringContainsString('verse.yaml was not found', $tester->getErrorOutput());
        $this->assertStringContainsString('warning: recovered an interrupted build', $tester->getErrorOutput());
        $this->assertSame('last good', file_get_contents($root.'/dist/index.html'));
        $this->assertSame([], glob($root.'/.verse/transactions/*') ?: []);
    }

    #[Test]
    public function a_directory_without_verse_yaml_is_left_untouched(): void
    {
        $root = $this->makeSite();
        $this->runBuild($root);

        $this->assertDirectoryDoesNotExist($root.'/.verse');
    }

    #[Test]
    public function a_theme_that_inlines_a_missing_file_exits_one_with_a_theme_error(): void
    {
        $root = $this->fixtureSite();
        $themes = $this->themeRegistry(ThemeSelectionTest::paperWith([
            'paper/views/layouts/base.blade.php' => '<html><head><script>{!! verse()->inline(\'src/missing.js\') !!}</script></head><body>@yield(\'content\')</body></html>',
        ]));
        $this->writeFiles($root, ['verse.yaml' => (string) file_get_contents($root.'/verse.yaml')."theme:\n  name: paper\n"]);

        try {
            $this->buildSite($root, themes: $themes);
            $this->fail('Expected a ThemeException');
        } catch (ThemeException $exception) {
            $this->assertStringContainsString('cannot inline "src/missing.js"', $exception->getMessage());
            $this->assertStringContainsString('themes/paper', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($root.'/dist');
    }
}
