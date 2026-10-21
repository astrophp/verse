<?php

declare(strict_types=1);

namespace Astro\Verse\Console;

use Astro\Verse\Build\BuildContext;
use Astro\Verse\Build\Builder;
use Astro\Verse\Config\ConfigLoader;
use Astro\Verse\Support\Warnings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'build', description: 'Build the site in the current directory into dist/')]
final class BuildCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = (string) getcwd();
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $hasRecoveryState = (glob($root.'/.verse/transactions/*.json') ?: []) !== [];
        if (! is_file($root.'/'.ConfigLoader::SITE_FILE) && ! $hasRecoveryState) {
            // nothing to build or recover here: report the missing file without creating .verse/
            (new ConfigLoader)->load($root, requireUrl: true);
        }

        $warnings = new Warnings;
        try {
            $result = Builder::bundled()->buildSite(
                $root,
                static fn (): BuildContext => BuildContext::create($root, (new ConfigLoader)->load($root, requireUrl: true)),
                $warnings,
            );
        } finally {
            foreach ($warnings->all() as $warning) {
                $errors->writeln('<comment>warning: '.$warning.'</comment>');
            }
        }

        $directory = str_starts_with($result->outputDir, $root.'/')
            ? substr($result->outputDir, strlen($root) + 1).'/'
            : $result->outputDir;
        $output->writeln(sprintf(
            'Built %d %s to %s in %.2fs.',
            $result->pageCount,
            $result->pageCount === 1 ? 'page' : 'pages',
            $directory,
            $result->seconds,
        ));

        return Command::SUCCESS;
    }
}
