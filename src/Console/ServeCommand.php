<?php

declare(strict_types=1);

namespace Astro\Verse\Console;

use Astro\Verse\Build\PathGuard;
use Astro\Verse\Config\ConfigLoader;
use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Serve\BuildStatus;
use Astro\Verse\Serve\RebuildScheduler;
use Astro\Verse\Serve\Watcher;
use Astro\Verse\Verse;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * `verse serve`: builds the site into `.verse/preview/`, serves it with `php -S` through the preview router and
 * rebuilds (in a worker process) when the inputs change.
 */
#[AsCommand(name: 'serve', description: 'Preview the site with live rebuilds')]
final class ServeCommand extends Command implements SignalableCommandInterface
{
    private const string HOST = '127.0.0.1';

    private const float POLL_SECONDS = 0.3;

    private const int TICK_MICROS = 25_000;

    private const int TAIL_LINES = 12;

    private OutputInterface $out;

    private OutputInterface $errors;

    private ?Process $server = null;

    private ?Process $worker = null;

    private bool $stopping = false;

    /** @var resource|null */
    private $lock;

    protected function configure(): void
    {
        $this
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port to listen on (127.0.0.1 only)', '8000')
            ->addOption('no-drafts', null, InputOption::VALUE_NONE, 'Preview exactly what `verse build` publishes: hide drafts and future-dated items');
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return extension_loaded('pcntl') ? [SIGINT, SIGTERM] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int
    {
        $this->shutdown();

        return Command::SUCCESS;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->out = $output;
        $this->errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $root = (string) getcwd();
        if (! is_file($root.'/'.ConfigLoader::SITE_FILE)) {
            (new ConfigLoader)->load($root, requireUrl: false);
        }

        $port = $input->getOption('port');
        if (! is_string($port) || preg_match('/^\d{1,5}$/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            throw new InvalidOptionException('--port must be a number between 1 and 65535.');
        }
        $port = (int) $port;
        $drafts = ! (bool) $input->getOption('no-drafts');

        $preview = $root.'/.verse/preview';
        PathGuard::assertState($root);
        if (! is_dir($preview) && ! @mkdir($preview, 0775, true) && ! is_dir($preview)) {
            throw new FilesystemException('cannot create '.$preview.'.');
        }
        if (! $this->acquireLock($preview.'/serve.lock')) {
            $this->errors->writeln('<error>error: verse serve is already running for this site.</error>');

            return Command::FAILURE;
        }

        try {
            return $this->serve($root, $port, $drafts);
        } finally {
            $this->shutdown();
        }
    }

    private function serve(string $root, int $port, bool $drafts): int
    {
        $preview = $root.'/.verse/preview';
        $statusFile = $preview.'/'.BuildStatus::FILE;
        $artifact = $preview.'/'.($drafts ? 'drafts' : 'public');

        $probe = @stream_socket_server('tcp://'.self::HOST.':'.$port, $errno, $errstr);
        if ($probe === false) {
            $this->errors->writeln('<error>error: cannot listen on '.self::HOST.':'.$port.' ('.$errstr.'). Choose another port with --port.</error>');

            return Command::FAILURE;
        }
        fclose($probe);

        $serveRoot = $root.'/.verse/serve-root';
        if (! is_dir($serveRoot) && ! @mkdir($serveRoot, 0775, true) && ! is_dir($serveRoot)) {
            throw new FilesystemException('cannot create '.$serveRoot.'.');
        }
        // a new session starts without a generation, so the router never mixes it with a stale artifact
        @unlink($statusFile);

        $watcher = new Watcher($root);
        $watcher->poll();

        $server = $this->server = new Process(
            [PHP_BINARY, '-S', self::HOST.':'.$port, '-t', $serveRoot, Verse::packageRoot().'/resources/router.php'],
            $root,
            ['PHP_CLI_SERVER_WORKERS' => false, 'VERSE_PREVIEW_DIR' => $artifact, 'VERSE_STATUS_FILE' => $statusFile],
            timeout: null,
        );
        $server->start();
        usleep(300_000);
        if (! $server->isRunning()) {
            $this->errors->writeln('<error>error: the preview server did not start.</error>');
            $detail = trim($server->getErrorOutput().$server->getOutput());
            if ($detail !== '') {
                $this->errors->writeln($detail);
            }

            return Command::FAILURE;
        }
        $server->clearOutput();
        $server->clearErrorOutput();

        $scheduler = new RebuildScheduler;
        $scheduler->requestNow($this->now());
        $announced = false;
        $statusBefore = null;
        $startedAt = 0.0;
        /** @var list<string> $tail */
        $tail = [];
        $nextPoll = 0.0;
        $worker = null;

        while (! $this->stopping) {
            $now = $this->now();

            if ($worker !== null) {
                $this->relay($worker, $tail);
                if (! $worker->isRunning()) {
                    $this->relay($worker, $tail);
                    $this->finishBuild($statusFile, $statusBefore, $worker->getExitCode() ?? 1, $tail, $now - $startedAt, $announced);
                    $worker = $this->worker = null;
                    $scheduler->finished();
                    $watcher->refreshTheme();
                    if (! $announced) {
                        $announced = true;
                        $this->announce($root, $port, $drafts, $statusFile);
                    }
                }
            }

            if ($this->hasStopped($server)) {
                $this->errors->writeln('<error>error: the preview server stopped unexpectedly.</error>');
                $detail = trim($server->getErrorOutput());
                if ($detail !== '') {
                    $this->errors->writeln($detail);
                }

                return Command::FAILURE;
            }
            $server->clearOutput();
            $server->clearErrorOutput();

            if ($now >= $nextPoll) {
                $nextPoll = $now + self::POLL_SECONDS;
                if ($watcher->poll()) {
                    $scheduler->changed($now);
                }
            }

            if ($scheduler->tick($now)) {
                $statusBefore = @file_get_contents($statusFile);
                $startedAt = $now;
                $tail = [];
                $command = [PHP_BINARY, Verse::packageRoot().'/bin/verse', '_build', '--root='.$root, '--out='.$artifact, '--mode=preview'];
                if ($drafts) {
                    $command[] = '--drafts';
                }
                $worker = $this->worker = new Process($command, $root, timeout: null);
                $worker->start();
            }

            usleep(self::TICK_MICROS);
        }

        return Command::SUCCESS;
    }

    private function hasStopped(Process $process): bool
    {
        return ! $process->isRunning();
    }

    /**
     * Prints the result line of a finished build; a worker that died without writing a status gets one written
     * here.
     *
     * @param  list<string>  $tail
     */
    private function finishBuild(string $statusFile, string|false|null $before, int $code, array $tail, float $seconds, bool $announced): void
    {
        $after = @file_get_contents($statusFile);
        $wroteStatus = $after !== false && $after !== $before;

        if ($code !== 0 && ! $wroteStatus) {
            $message = 'build worker exited with code '.$code;
            $detail = implode("\n", array_slice($tail, -self::TAIL_LINES));
            $status = BuildStatus::failed(BuildStatus::read($statusFile), 'internal', $detail === '' ? $message : $message."\n".$detail, null);
            try {
                $status->write($statusFile);
            } catch (Throwable $exception) {
                $this->errors->writeln('<comment>warning: could not write the preview status: '.$exception->getMessage().'</comment>');
            }
            $this->errors->writeln('<error>error: '.$message.'</error>');

            return;
        }
        if ($code === 0 && $announced) {
            $this->out->writeln(sprintf('rebuilt in %.2fs', $seconds));
        }
    }

    private function announce(string $root, int $port, bool $drafts, string $statusFile): void
    {
        $title = 'site';
        $basePath = '/';
        try {
            $config = (new ConfigLoader)->load($root, requireUrl: false);
            $title = $config->title;
            $basePath = $config->basePath();
        } catch (Throwable) {
            // the first build reported the problem; keep serving the diagnostic page
        }
        $status = BuildStatus::read($statusFile);
        if ($status?->result === 'ok') {
            $basePath = $status->basePath;
        }

        $this->out->writeln('Serving '.$title.' at http://'.self::HOST.':'.$port.$basePath.($drafts
            ? ' (drafts and scheduled posts shown; --no-drafts to hide them)'
            : ' (published content only)'));
        $this->out->writeln('Press Ctrl-C to stop.');
    }

    /**
     * Passes the worker's new stderr output (warnings, errors) on to the terminal and remembers its last lines.
     *
     * @param  list<string>  $tail
     */
    private function relay(Process $worker, array &$tail): void
    {
        $chunk = $worker->getIncrementalErrorOutput();
        $worker->clearErrorOutput();
        $worker->clearOutput();
        if ($chunk === '') {
            return;
        }

        $this->errors->write($chunk, false, OutputInterface::OUTPUT_RAW);
        foreach (preg_split('/\R/', rtrim($chunk)) ?: [] as $line) {
            $tail[] = $line;
        }
        $tail = array_slice($tail, -self::TAIL_LINES);
    }

    private function acquireLock(string $path): bool
    {
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new FilesystemException('cannot open the preview lock '.$path.'.');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $this->lock = $handle;

        return true;
    }

    private function shutdown(): void
    {
        $this->stopping = true;
        foreach ([$this->worker, $this->server] as $process) {
            if ($process !== null && $process->isRunning()) {
                $process->stop(2);
            }
        }
        $this->worker = null;
        $this->server = null;

        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
        $this->lock = null;
    }

    private function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
