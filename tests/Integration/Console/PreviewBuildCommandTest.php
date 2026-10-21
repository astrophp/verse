<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Console;

use Astro\Verse\Build\OutputTransaction;
use Astro\Verse\Console\Application;
use Astro\Verse\Serve\BuildStatus;
use Astro\Verse\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\ApplicationTester;

final class PreviewBuildCommandTest extends TestCase
{
    /**
     * @param  array<string, string|bool|null>  $options
     */
    private function worker(string $root, array $options = [], ?string $out = null): ApplicationTester
    {
        $application = new Application;
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(
            $options + ['command' => '_build', '--root' => $root, '--out' => $out ?? $root.'/.verse/preview/public', '--mode' => 'preview'],
            ['capture_stderr_separately' => true],
        );

        return $tester;
    }

    private function statusOf(string $root): BuildStatus
    {
        $status = BuildStatus::read($root.'/.verse/preview/status.json');
        $this->assertNotNull($status);

        return $status;
    }

    #[Test]
    public function a_successful_build_writes_an_ok_status_with_the_artifact_generation(): void
    {
        $root = $this->fixtureSite();
        $tester = $this->worker($root);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertSame('', $tester->getErrorOutput());
        $this->assertFileExists($root.'/.verse/preview/public/index.html');
        $this->assertDirectoryDoesNotExist($root.'/dist');

        $marker = OutputTransaction::markerId($root.'/.verse/preview/public');
        $this->assertNotNull($marker);
        $status = $this->statusOf($root);
        $this->assertSame('ok', $status->result);
        $this->assertSame($marker, $status->generation);
        $this->assertSame('/', $status->basePath);
        $this->assertNull($status->error);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $status->time);

        $raw = json_decode((string) file_get_contents($root.'/.verse/preview/status.json'), true);
        $this->assertSame(['generation', 'basePath', 'result', 'error', 'time'], array_keys($raw));
    }

    #[Test]
    public function the_url_is_optional_and_a_base_path_is_reported(): void
    {
        $root = $this->fixtureSite('notes', ['verse.yaml' => "title: Notes\n"]);
        $this->assertSame(0, $this->worker($root)->getStatusCode());
        $this->assertSame('/', $this->statusOf($root)->basePath);

        $root = $this->fixtureSite('notes', ['verse.yaml' => "title: Notes\nurl: https://example.test/notes/\n"]);
        $this->assertSame(0, $this->worker($root)->getStatusCode());
        $this->assertSame('/notes/', $this->statusOf($root)->basePath);
    }

    #[Test]
    public function each_build_gets_a_new_generation(): void
    {
        $root = $this->fixtureSite();
        $this->worker($root);
        $first = $this->statusOf($root)->generation;
        $this->worker($root);

        $this->assertNotNull($first);
        $this->assertNotSame($first, $this->statusOf($root)->generation);
    }

    #[Test]
    public function drafts_are_built_only_on_request(): void
    {
        $root = $this->fixtureSite();
        $this->worker($root);
        $this->assertFileDoesNotExist($root.'/.verse/preview/public/articles/draft-notes/index.html');
        $this->assertStringNotContainsString('state-badge', (string) file_get_contents($root.'/.verse/preview/public/index.html'));
        $this->assertStringNotContainsString('"state"', (string) file_get_contents($root.'/.verse/preview/public/search.json'));

        $this->worker($root, ['--drafts' => true], $root.'/.verse/preview/drafts');
        $this->assertFileExists($root.'/.verse/preview/drafts/articles/draft-notes/index.html');
        $this->assertStringContainsString('state-badge', (string) file_get_contents($root.'/.verse/preview/drafts/articles/draft-notes/index.html'));
    }

    #[Test]
    public function a_content_error_writes_an_error_status_and_keeps_the_previous_generation_and_artifact(): void
    {
        $root = $this->fixtureSite('notes', ['verse.yaml' => "title: Notes\nurl: https://example.test/notes/\n"]);
        $this->assertSame(0, $this->worker($root)->getStatusCode());
        $good = $this->statusOf($root);
        $html = (string) file_get_contents($root.'/.verse/preview/public/index.html');

        $this->writeFiles($root, ['content/articles/broken.md' => "---\ntitle: Broken\ndate: soon\n---\nText\n"]);
        $tester = $this->worker($root);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('error: content error in content/articles/broken.md, field "date"', $tester->getErrorOutput());
        $status = $this->statusOf($root);
        $this->assertSame('error', $status->result);
        $this->assertSame($good->generation, $status->generation);
        $this->assertSame('/notes/', $status->basePath);
        $this->assertNotNull($status->error);
        $this->assertSame('content', $status->error['kind']);
        $this->assertSame('content/articles/broken.md', $status->error['file']);
        $this->assertStringContainsString('field "date"', $status->error['message']);
        $this->assertSame($good->generation, OutputTransaction::markerId($root.'/.verse/preview/public'));
        $this->assertSame($html, file_get_contents($root.'/.verse/preview/public/index.html'));

        unlink($root.'/content/articles/broken.md');
        $this->assertSame(0, $this->worker($root)->getStatusCode());
        $recovered = $this->statusOf($root);
        $this->assertSame('ok', $recovered->result);
        $this->assertNull($recovered->error);
        $this->assertNotSame($good->generation, $recovered->generation);
    }

    #[Test]
    public function a_failing_first_build_has_no_generation(): void
    {
        $root = $this->fixtureSite('notes', ['content/articles/broken.md' => "---\ntitle: Broken\ndate: soon\n---\nText\n"]);
        $tester = $this->worker($root);

        $this->assertSame(1, $tester->getStatusCode());
        $status = $this->statusOf($root);
        $this->assertSame('error', $status->result);
        $this->assertNull($status->generation);
        $this->assertSame('/', $status->basePath);
        $this->assertDirectoryDoesNotExist($root.'/.verse/preview/public');
    }

    #[Test]
    public function a_config_error_is_reported_with_its_kind(): void
    {
        $root = $this->fixtureSite('notes', ['verse.yaml' => "title: Notes\nbogus: 1\n"]);
        $tester = $this->worker($root);

        $this->assertSame(1, $tester->getStatusCode());
        $status = $this->statusOf($root);
        $this->assertNotNull($status->error);
        $this->assertSame('config', $status->error['kind']);
        $this->assertSame('verse.yaml', $status->error['file']);
    }

    #[Test]
    public function warnings_go_to_stderr_and_stdout_stays_empty(): void
    {
        $root = $this->fixtureSite('notes', ['verse.yaml' => "title: Notes\nurl: https://example.com/\n"]);
        $tester = $this->worker($root);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
        $this->assertStringContainsString('warning: verse.yaml: url uses the placeholder host "example.com"', $tester->getErrorOutput());
        $this->assertSame('ok', $this->statusOf($root)->result);
    }

    #[Test]
    public function the_output_must_be_inside_the_preview_directory(): void
    {
        $root = $this->fixtureSite();
        $tester = $this->worker($root, [], $root.'/dist');

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('preview output must be inside .verse/preview/', $tester->getErrorOutput());
        $this->assertDirectoryDoesNotExist($root.'/dist');
    }

    #[Test]
    public function the_options_are_validated(): void
    {
        $root = $this->fixtureSite();

        $this->assertSame(1, $this->worker($root, ['--mode' => 'production'])->getStatusCode());
        $this->assertSame(1, $this->worker($root, [], 'relative/out')->getStatusCode());
        $this->assertSame(1, $this->worker('relative/root')->getStatusCode());
        $this->assertFileDoesNotExist($root.'/.verse/preview/status.json');
    }

    #[Test]
    public function the_command_is_hidden(): void
    {
        $application = new Application;
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'list']);

        $this->assertStringContainsString('serve', $tester->getDisplay());
        $this->assertStringNotContainsString('_build', $tester->getDisplay());
    }
}
