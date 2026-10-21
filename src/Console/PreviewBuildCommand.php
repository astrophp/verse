<?php

declare(strict_types=1);

namespace Astro\Verse\Console;

use Astro\Verse\Build\BuildContext;
use Astro\Verse\Build\Builder;
use Astro\Verse\Build\BuildMode;
use Astro\Verse\Build\OutputTransaction;
use Astro\Verse\Build\PathGuard;
use Astro\Verse\Config\ConfigLoader;
use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Error\VerseException;
use Astro\Verse\Serve\BuildStatus;
use Astro\Verse\Support\Warnings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The build worker of `verse serve`: a preview build into `.verse/preview/{public,drafts}/` through the same
 * Builder and output transaction as `verse build`, followed by an atomic update of `.verse/preview/status.json`.
 */
#[AsCommand(name: '_build', description: 'Internal: preview build for `verse serve`', hidden: true)]
final class PreviewBuildCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Absolute site root')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Absolute output directory inside .verse/preview/')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Build mode (only "preview")')
            ->addOption('drafts', null, InputOption::VALUE_NONE, 'Include drafts and future-dated items');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $input->getOption('root');
        $out = $input->getOption('out');
        if (! is_string($root) || ! str_starts_with($root, '/') || ! is_dir($root)) {
            throw new InvalidOptionException('--root must be the absolute path of an existing site folder.');
        }
        if (! is_string($out) || ! str_starts_with($out, '/')) {
            throw new InvalidOptionException('--out must be an absolute path.');
        }
        if ($input->getOption('mode') !== BuildMode::Preview->value) {
            throw new InvalidOptionException('--mode must be "preview".');
        }

        $root = rtrim($root, '/');
        $drafts = (bool) $input->getOption('drafts');
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $statusFile = $root.'/.verse/preview/'.BuildStatus::FILE;
        $previous = BuildStatus::read($statusFile);

        $warnings = new Warnings;
        $basePath = '/';
        try {
            $result = Builder::bundled()->buildSite(
                $root,
                static function () use ($root, $out, $drafts, &$basePath): BuildContext {
                    $context = BuildContext::create(
                        $root,
                        (new ConfigLoader)->load($root, requireUrl: false),
                        BuildMode::Preview,
                        $drafts,
                        outputDir: $out,
                    );
                    $basePath = $context->basePath;

                    return $context;
                },
                $warnings,
                $out,
            );

            $generation = OutputTransaction::markerId($result->outputDir);
            if ($generation === null) {
                throw new FilesystemException('the preview output has no valid .verse-output marker.');
            }
            $this->writeStatus($root, $statusFile, BuildStatus::ok($generation, $basePath));
        } catch (VerseException $exception) {
            $this->writeStatus($root, $statusFile, BuildStatus::failed($previous, $exception->kind()->value, $exception->getMessage(), $exception->sourceFile), $errors);

            throw $exception;
        } catch (Throwable $exception) {
            $this->writeStatus($root, $statusFile, BuildStatus::failed($previous, 'internal', 'unexpected failure: '.$exception->getMessage(), null), $errors);

            throw $exception;
        } finally {
            foreach ($warnings->all() as $warning) {
                $errors->writeln('<comment>warning: '.$warning.'</comment>');
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Writes the status file; when this is the error path, a failure to write is reported but never hides the
     * build error itself.
     */
    private function writeStatus(string $root, string $statusFile, BuildStatus $status, ?OutputInterface $errors = null): void
    {
        try {
            PathGuard::assertState($root);
            $status->write($statusFile);
        } catch (VerseException $exception) {
            if ($errors === null) {
                throw $exception;
            }
            $errors->writeln('<comment>warning: could not write the preview status: '.$exception->getMessage().'</comment>');
        }
    }
}
