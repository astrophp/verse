<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Console;

use Astro\Verse\Console\Application;
use Astro\Verse\Error\ConfigException;
use Astro\Verse\Error\ContentException;
use Astro\Verse\Error\ErrorKind;
use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Error\ThemeException;
use Astro\Verse\Verse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

final class ApplicationTest extends TestCase
{
    private function tester(): ApplicationTester
    {
        $application = new Application;
        $application->setAutoExit(false);

        return new ApplicationTester($application);
    }

    private function throwingCommand(\Throwable $exception): Command
    {
        return new class($exception) extends Command
        {
            public function __construct(private readonly \Throwable $exception)
            {
                parent::__construct('boom');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                throw $this->exception;
            }
        };
    }

    #[Test]
    public function version_prints_the_product_and_version(): void
    {
        $tester = $this->tester();
        $exit = $tester->run(['--version' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame('Verse '.Verse::VERSION."\n", $tester->getDisplay());
        $this->assertSame('Verse 0.1.0-dev', trim($tester->getDisplay()));
    }

    #[Test]
    public function list_runs(): void
    {
        $tester = $this->tester();
        $exit = $tester->run(['command' => 'list']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Available commands', $tester->getDisplay());
    }

    #[Test]
    public function expected_errors_exit_one_with_an_error_prefix(): void
    {
        $application = new Application;
        $application->setAutoExit(false);
        $application->addCommand($this->throwingCommand(new ContentException('bad date.', 'content/x.md', 'date')));
        $tester = new ApplicationTester($application);

        $exit = $tester->run(['command' => 'boom'], ['capture_stderr_separately' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('error: content error in content/x.md, field "date": bad date.', $tester->getErrorOutput());
    }

    #[Test]
    public function unexpected_errors_exit_two_and_show_a_trace_only_when_verbose(): void
    {
        $application = new Application;
        $application->setAutoExit(false);
        $application->addCommand($this->throwingCommand(new RuntimeException('kaput')));
        $tester = new ApplicationTester($application);

        $exit = $tester->run(['command' => 'boom'], ['capture_stderr_separately' => true]);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('error: unexpected failure: kaput', $tester->getErrorOutput());
        $this->assertStringNotContainsString('#0', $tester->getErrorOutput());

        $exit = $tester->run(['command' => 'boom', '-v' => true], ['capture_stderr_separately' => true]);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('#0', $tester->getErrorOutput());
    }

    #[Test]
    public function unknown_commands_are_usage_errors(): void
    {
        $tester = $this->tester();

        $this->assertSame(1, $tester->run(['command' => 'nope']));
        $this->assertStringContainsString('Command "nope" is not defined', $tester->getDisplay());
    }

    #[Test]
    public function the_executable_runs_from_an_unrelated_directory(): void
    {
        $bin = Verse::packageRoot().'/bin/verse';
        $process = new Process([PHP_BINARY, $bin, '--version'], sys_get_temp_dir());
        $process->run();

        $this->assertSame(0, $process->getExitCode());
        $this->assertSame('Verse 0.1.0-dev', trim($process->getOutput()));
    }

    #[Test]
    public function the_executable_is_marked_executable_with_a_php_shebang(): void
    {
        $bin = Verse::packageRoot().'/bin/verse';

        $this->assertTrue(is_executable($bin));
        $this->assertSame("#!/usr/bin/env php\n", file_get_contents($bin, length: 19));
    }

    #[Test]
    public function package_paths_point_at_the_checkout(): void
    {
        $this->assertSame(dirname(__DIR__, 3), Verse::packageRoot());
        $this->assertSame(Verse::packageRoot().'/themes', Verse::themesPath());
        $this->assertFileExists(Verse::themesPath().'/default/theme.yaml');
    }

    #[Test]
    public function error_messages_have_a_uniform_shape(): void
    {
        $content = new ContentException('expected YYYY-MM-DD, got "soon".', 'content/articles/x.md', 'date');
        $this->assertSame(ErrorKind::Content, $content->kind());
        $this->assertSame('content error in content/articles/x.md, field "date": expected YYYY-MM-DD, got "soon".', $content->getMessage());

        $routed = new ContentException('clash.', route: '/a/');
        $this->assertSame('content error route /a/: clash.', $routed->getMessage());

        $plain = new ConfigException('missing.');
        $this->assertSame('config error: missing.', $plain->getMessage());

        $this->assertSame(ErrorKind::Theme, (new ThemeException('x'))->kind());
        $this->assertSame(ErrorKind::Filesystem, (new FilesystemException('x', 'dist'))->kind());
    }
}
