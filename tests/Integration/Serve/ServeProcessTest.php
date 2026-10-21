<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Serve;

use Astro\Verse\Serve\BuildStatus;
use Astro\Verse\Tests\Support\TestCase;
use Astro\Verse\Verse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

/**
 * Runs the real `bin/verse serve` in a temporary copy of a fixture site and talks to it over HTTP.
 */
final class ServeProcessTest extends TestCase
{
    /** @var list<Process> */
    private array $processes = [];

    private static string|false|null $intlIni = null;

    protected function setUp(): void
    {
        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('verse serve process tests need the pcntl extension (SIGTERM handling); it is not loaded.');
        }
        if (self::childEnvironment() === null) {
            $this->markTestSkipped('The PHP binary '.PHP_BINARY.' has no ext-intl and none was found next to it; verse cannot run in a child process.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(5);
            }
        }
        $this->processes = [];
        parent::tearDown();
    }

    #[Test]
    public function it_serves_rebuilds_survives_errors_refuses_a_second_session_and_stops_on_sigterm(): void
    {
        $root = $this->fixtureSite();
        $port = $this->freePort();
        $serve = $this->serve($root, $port, ['--no-drafts']);
        $this->waitForOutput($serve, 'Serving Notes at http://127.0.0.1:'.$port.'/ (published content only)');

        $home = $this->request($port, '/');
        $this->assertSame(200, $home['status']);
        $this->assertStringContainsString('<title>Notes</title>', $home['body']);
        $this->assertStringContainsString('data-verse-live-reload', $home['body']);
        $first = $this->statusOf($root);
        $this->assertSame('ok', $first->result);
        $this->assertNotNull($first->generation);
        $this->assertStringContainsString('var g="'.$first->generation.'"', $home['body']);

        $status = $this->request($port, '/__verse/status');
        $this->assertSame('application/json', $status['headers']['content-type']);
        $this->assertSame('no-store', $status['headers']['cache-control']);
        $this->assertSame($first->generation, json_decode($status['body'], true)['generation']);

        $this->assertSame(404, $this->request($port, '/verse.yaml')['status']);
        $this->assertSame(404, $this->request($port, '/content/about.md')['status']);
        $this->assertSame(404, $this->request($port, '/articles/draft-notes/')['status']);
        $redirect = $this->request($port, '/essays/trace');
        $this->assertSame(301, $redirect['status']);
        $this->assertSame('/essays/trace/', $redirect['headers']['location']);
        $this->assertSame(200, $this->request($port, '/essays/trace/')['status']);
        $this->assertSame(200, $this->request($port, '/.well-known/security.txt')['status']);
        $this->assertSame(405, $this->request($port, '/', 'POST')['status']);
        $this->assertSame('', $this->request($port, '/', 'HEAD')['body']);

        // an edit rebuilds and the new content is served
        file_put_contents($root.'/content/about.md', (string) file_get_contents($root.'/content/about.md')."\nEdited while serving.\n");
        $second = $this->waitForStatus($root, fn (BuildStatus $s): bool => $s->result === 'ok' && $s->generation !== $first->generation);
        $this->assertStringContainsString('Edited while serving.', $this->request($port, '/about/')['body']);
        $this->assertStringContainsString('var g="'.$second->generation.'"', $this->request($port, '/about/')['body']);
        $this->waitForOutput($serve, 'rebuilt in ');

        // a front matter error: status error, previous generation and HTML stay
        $this->writeFiles($root, ['content/articles/broken.md' => "---\ntitle: Broken\ndate: soon\n---\nText\n"]);
        $failed = $this->waitForStatus($root, fn (BuildStatus $s): bool => $s->result === 'error');
        $this->assertSame($second->generation, $failed->generation);
        $this->assertNotNull($failed->error);
        $this->assertSame('content', $failed->error['kind']);
        $this->assertSame('content/articles/broken.md', $failed->error['file']);
        $this->assertStringContainsString('field "date"', $failed->error['message']);
        $this->assertStringContainsString('Edited while serving.', $this->request($port, '/about/')['body']);
        $this->assertSame('error', json_decode($this->request($port, '/__verse/status')['body'], true)['result']);
        $this->waitForOutput($serve, 'error: content error in content/articles/broken.md', stream: 'error');

        // fixing it recovers with a new generation
        unlink($root.'/content/articles/broken.md');
        $fixed = $this->waitForStatus($root, fn (BuildStatus $s): bool => $s->result === 'ok' && $s->generation !== $second->generation);
        $this->assertNull($fixed->error);
        $this->assertSame(200, $this->request($port, '/about/')['status']);

        // a second session for the same site is refused and leaves the first one alone
        $other = new Process([PHP_BINARY, Verse::packageRoot().'/bin/verse', 'serve', '--port='.$this->freePort()], $root, self::childEnvironment());
        $other->run();
        $this->assertSame(1, $other->getExitCode());
        $this->assertStringContainsString('verse serve is already running for this site', $other->getErrorOutput());
        $this->assertSame(200, $this->request($port, '/')['status']);

        // SIGTERM stops the command, the server and any worker
        $serve->signal(SIGTERM);
        $serve->wait();
        $this->assertSame(0, $serve->getExitCode(), $serve->getErrorOutput());
        $this->waitFor(fn (): bool => $this->processesMentioning($root) === [], 'the server and worker processes to exit');
        $this->assertFalse(@stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $errstr, 1));
    }

    #[Test]
    public function a_failed_first_build_serves_a_diagnostic_page_until_it_is_fixed(): void
    {
        $root = $this->fixtureSite('notes', ['content/articles/broken.md' => "---\ntitle: Broken <b>\ndate: soon\n---\nText\n"]);
        $port = $this->freePort();
        $serve = $this->serve($root, $port);
        $this->waitForOutput($serve, 'Serving Notes at');

        $page = $this->request($port, '/');
        $this->assertSame(503, $page['status']);
        $this->assertStringContainsString('The build failed', $page['body']);
        $this->assertStringContainsString('content/articles/broken.md', $page['body']);
        $this->assertStringContainsString('var g=null', $page['body']);
        $this->assertSame(503, $this->request($port, '/about/')['status']);
        $status = $this->statusOf($root);
        $this->assertSame('error', $status->result);
        $this->assertNull($status->generation);

        unlink($root.'/content/articles/broken.md');
        $ok = $this->waitForStatus($root, fn (BuildStatus $s): bool => $s->result === 'ok');
        $this->assertNotNull($ok->generation);
        $home = $this->request($port, '/');
        $this->assertSame(200, $home['status']);
        $this->assertStringContainsString('var g="'.$ok->generation.'"', $home['body']);
    }

    #[Test]
    public function a_url_with_a_base_path_is_served_under_it_and_a_url_change_takes_effect(): void
    {
        $root = $this->fixtureSite('notes', ['verse.yaml' => "title: Notes\nurl: https://example.test/notes/\n"]);
        $port = $this->freePort();
        $serve = $this->serve($root, $port);
        $this->waitForOutput($serve, 'Serving Notes at http://127.0.0.1:'.$port.'/notes/');

        $this->assertSame('/notes/', $this->statusOf($root)->basePath);
        $bare = $this->request($port, '/');
        $this->assertSame(302, $bare['status']);
        $this->assertSame('/notes/', $bare['headers']['location']);
        $this->assertSame(200, $this->request($port, '/notes/')['status']);
        $this->assertSame(200, $this->request($port, '/notes/about/')['status']);
        $this->assertStringContainsString('/notes/assets/theme/', $this->request($port, '/notes/')['body']);
        $this->assertSame(404, $this->request($port, '/about/')['status']);
        $this->assertSame(200, $this->request($port, '/__verse/status')['status']);

        $before = $this->statusOf($root)->generation;
        file_put_contents($root.'/verse.yaml', "title: Notes\nurl: https://example.test/\n");
        $this->waitForStatus($root, fn (BuildStatus $s): bool => $s->result === 'ok' && $s->generation !== $before);

        $this->assertSame('/', $this->statusOf($root)->basePath);
        $this->assertSame(200, $this->request($port, '/about/')['status']);
        $this->assertSame(404, $this->request($port, '/notes/about/')['status']);
    }

    #[Test]
    public function drafts_are_served_by_default_with_a_badge(): void
    {
        $root = $this->fixtureSite();
        $port = $this->freePort();
        $serve = $this->serve($root, $port);
        $this->waitForOutput($serve, 'Serving Notes at http://127.0.0.1:'.$port.'/ (drafts and scheduled posts shown; --no-drafts to hide them)');

        $draft = $this->request($port, '/articles/draft-notes/');
        $this->assertSame(200, $draft['status']);
        $this->assertStringContainsString('<span class="state-badge">Draft</span>', $draft['body']);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $draft['body']);
        $this->assertStringContainsString('<span class="state-badge">Scheduled</span>', $this->request($port, '/articles/future-article/')['body']);
        $this->assertStringContainsString('state-badge', $this->request($port, '/')['body']);
        $this->assertDirectoryExists($root.'/.verse/preview/drafts');
        $this->assertDirectoryDoesNotExist($root.'/.verse/preview/public');
    }

    #[Test]
    public function no_drafts_previews_exactly_what_build_publishes(): void
    {
        $root = $this->fixtureSite();
        $port = $this->freePort();
        $serve = $this->serve($root, $port, ['--no-drafts']);
        $this->waitForOutput($serve, '(published content only)');

        $this->assertSame(404, $this->request($port, '/articles/draft-notes/')['status']);
        $this->assertSame(404, $this->request($port, '/articles/future-article/')['status']);
        $this->assertStringNotContainsString('state-badge', $this->request($port, '/')['body']);
        $this->assertDirectoryExists($root.'/.verse/preview/public');
        $this->assertDirectoryDoesNotExist($root.'/.verse/preview/drafts');
    }

    #[Test]
    public function the_old_drafts_option_no_longer_exists(): void
    {
        $other = new Process([PHP_BINARY, Verse::packageRoot().'/bin/verse', 'serve', '--drafts', '--port='.$this->freePort()], $this->fixtureSite(), self::childEnvironment());
        $other->run();

        $this->assertSame(1, $other->getExitCode());
        $this->assertStringContainsString('"--drafts" option does not exist', $other->getErrorOutput());
    }

    #[Test]
    public function a_burst_of_edits_is_coalesced_into_few_rebuilds(): void
    {
        $root = $this->fixtureSite();
        $port = $this->freePort();
        $serve = $this->serve($root, $port);
        $this->waitForOutput($serve, 'Serving Notes at');
        $before = $this->statusOf($root)->generation;

        foreach (range(1, 8) as $n) {
            file_put_contents($root.'/content/about.md', (string) file_get_contents($root.'/content/about.md')."\nEdit {$n}.\n");
            usleep(30_000);
        }
        $this->waitForStatus($root, fn (BuildStatus $s): bool => $s->result === 'ok' && $s->generation !== $before);
        usleep(1_500_000);

        $this->assertStringContainsString('Edit 8.', $this->request($port, '/about/')['body']);
        $this->assertLessThanOrEqual(2, substr_count($serve->getOutput(), 'rebuilt in '));
    }

    #[Test]
    public function a_worker_that_dies_without_a_status_is_reported_by_serve(): void
    {
        $directory = $this->makeSite(['crash.php' => "<?php\nif (in_array('_build', \$_SERVER['argv'] ?? [], true)) {\n    fwrite(STDERR, \"boom from the worker\\n\");\n    exit(255);\n}\n"]);
        $root = $this->fixtureSite();
        $port = $this->freePort();
        $serve = $this->serve($root, $port, environment: self::childEnvironment("auto_prepend_file={$directory}/crash.php"));
        $this->waitForOutput($serve, 'Serving Notes at');

        $status = $this->statusOf($root);
        $this->assertSame('error', $status->result);
        $this->assertNull($status->generation);
        $this->assertNotNull($status->error);
        $this->assertStringContainsString('build worker exited with code 255', $status->error['message']);
        $this->assertStringContainsString('boom from the worker', $status->error['message']);
        $this->assertStringContainsString('error: build worker exited with code 255', $serve->getErrorOutput());
        $page = $this->request($port, '/');
        $this->assertSame(503, $page['status']);
        $this->assertStringContainsString('build worker exited with code 255', $page['body']);
        $this->assertSame(0, $this->stopAndGetExitCode($serve));
    }

    #[Test]
    public function a_port_that_is_taken_is_refused(): void
    {
        $taken = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($taken);
        $port = (int) substr((string) stream_socket_get_name($taken, false), strlen('127.0.0.1:'));

        $process = new Process([PHP_BINARY, Verse::packageRoot().'/bin/verse', 'serve', '--port='.$port], $this->fixtureSite(), self::childEnvironment());
        $process->run();
        fclose($taken);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('cannot listen on 127.0.0.1:'.$port, $process->getErrorOutput());
    }

    #[Test]
    public function serve_needs_a_verse_yaml_and_a_valid_port(): void
    {
        $empty = new Process([PHP_BINARY, Verse::packageRoot().'/bin/verse', 'serve'], $this->makeSite(), self::childEnvironment());
        $empty->run();
        $this->assertSame(1, $empty->getExitCode());
        $this->assertStringContainsString('verse.yaml was not found', $empty->getErrorOutput());

        $badPort = new Process([PHP_BINARY, Verse::packageRoot().'/bin/verse', 'serve', '--port=99999'], $this->fixtureSite(), self::childEnvironment());
        $badPort->run();
        $this->assertSame(1, $badPort->getExitCode());
        $this->assertStringContainsString('--port must be a number between 1 and 65535', $badPort->getErrorOutput());
    }

    /**
     * @param  list<string>  $arguments
     * @param  ?array<string, string>  $environment
     */
    private function serve(string $root, int $port, array $arguments = [], ?array $environment = null): Process
    {
        $process = new Process(
            [PHP_BINARY, Verse::packageRoot().'/bin/verse', 'serve', '--port='.$port, ...$arguments],
            $root,
            $environment ?? self::childEnvironment(),
            timeout: null,
        );
        $process->start();
        $this->processes[] = $process;

        return $process;
    }

    private function stopAndGetExitCode(Process $process): ?int
    {
        $process->signal(SIGTERM);
        $process->wait();

        return $process->getExitCode();
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $port = (int) substr((string) stream_socket_get_name($socket, false), strlen('127.0.0.1:'));
        fclose($socket);

        return $port;
    }

    private function statusOf(string $root): BuildStatus
    {
        $status = BuildStatus::read($root.'/.verse/preview/status.json');
        $this->assertNotNull($status, 'status.json is missing');

        return $status;
    }

    /**
     * @param  callable(BuildStatus): bool  $condition
     */
    private function waitForStatus(string $root, callable $condition): BuildStatus
    {
        $found = null;
        $this->waitFor(function () use ($root, $condition, &$found): bool {
            $status = BuildStatus::read($root.'/.verse/preview/status.json');
            if ($status !== null && $condition($status)) {
                $found = $status;

                return true;
            }

            return false;
        }, 'the preview status to change');
        $this->assertInstanceOf(BuildStatus::class, $found);

        return $found;
    }

    private function waitForOutput(Process $process, string $needle, string $stream = 'output'): void
    {
        $this->waitFor(function () use ($process, $needle, $stream): bool {
            $text = $stream === 'output' ? $process->getOutput() : $process->getErrorOutput();
            if (str_contains($text, $needle)) {
                return true;
            }
            if (! $process->isRunning()) {
                $this->fail('verse serve exited ('.$process->getExitCode().') before printing "'.$needle.'". stdout: '.$process->getOutput().' stderr: '.$process->getErrorOutput());
            }

            return false;
        }, 'verse serve to print "'.$needle.'"', 30.0);
    }

    /**
     * @param  callable(): bool  $condition
     */
    private function waitFor(callable $condition, string $what, float $seconds = 15.0): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }
            usleep(100_000);
        }

        $this->fail('Timed out after '.$seconds.'s waiting for '.$what.'.');
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function request(int $port, string $path, string $method = 'GET'): array
    {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
        $body = @file_get_contents('http://127.0.0.1:'.$port.$path, false, $context);
        $this->assertNotFalse($body, 'no response for '.$method.' '.$path);

        $lines = http_get_last_response_headers() ?? [];
        $this->assertSame(1, preg_match('~^HTTP/\S+ (\d{3})~', $lines[0], $m));
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)] = trim($value);
            }
        }

        return ['status' => (int) ($m[1] ?? 0), 'headers' => $headers, 'body' => $body];
    }

    /**
     * @return list<string> command lines of running processes that mention `$needle`
     */
    private function processesMentioning(string $needle): array
    {
        $ps = new Process(['ps', '-axo', 'command=']);
        $ps->run();

        return array_values(array_filter(
            explode("\n", $ps->getOutput()),
            static fn (string $line): bool => str_contains($line, $needle),
        ));
    }

    /**
     * The environment for child PHP processes, null when verse cannot run in them. A PHP binary without ext-intl
     * (the 8.4 install of the floor check loads it with `-d extension=`) gets it through an ini scan directory
     * that all grandchildren inherit.
     *
     * @return ?array<string, string>
     */
    private static function childEnvironment(?string $extraIni = null): ?array
    {
        self::$intlIni ??= self::intlIni();
        if (self::$intlIni === false) {
            return null;
        }
        if (self::$intlIni === '' && $extraIni === null) {
            return [];
        }

        $directory = sys_get_temp_dir().'/verse-test-ini-'.bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory.'/verse.ini', self::$intlIni."\n".($extraIni ?? '')."\n");
        register_shutdown_function(static function () use ($directory): void {
            @unlink($directory.'/verse.ini');
            @rmdir($directory);
        });

        return ['PHP_INI_SCAN_DIR' => $directory];
    }

    /**
     * The ini line that loads ext-intl in a child PHP ('' when it loads by itself, false when it cannot be loaded).
     */
    private static function intlIni(): string|false
    {
        $probe = new Process([PHP_BINARY, '-r', 'echo extension_loaded("intl") ? "yes" : "no";']);
        $probe->run();
        if (str_ends_with(trim($probe->getOutput()), 'yes')) {
            return '';
        }

        $found = glob(dirname(realpath(PHP_BINARY) ?: PHP_BINARY, 2).'/lib/php/*/intl.so') ?: [];

        return $found === [] ? false : 'extension='.$found[0];
    }
}
