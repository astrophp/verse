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

#[AsCommand(name: 'check', description: 'Check the site in the current directory without writing dist/')]
final class CheckCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = (string) getcwd();
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $warnings = new Warnings;
        try {
            $result = Builder::bundled()->checkSite(
                static fn (): BuildContext => BuildContext::create($root, (new ConfigLoader)->load($root, requireUrl: true)),
                $warnings,
            );
        } finally {
            foreach ($warnings->all() as $warning) {
                $errors->writeln('<comment>warning: '.$warning.'</comment>');
            }
        }

        $output->writeln(sprintf(
            'Checked %d %s in %.2fs. dist/ was not changed.',
            $result->pageCount,
            $result->pageCount === 1 ? 'page' : 'pages',
            $result->seconds,
        ));

        return Command::SUCCESS;
    }
}
