<?php

declare(strict_types=1);

namespace Astro\Verse\Console;

use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Init\ProjectInitializer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'init', description: 'Create a starter Verse site in a new or empty directory')]
final class InitCommand extends Command
{
    public function __construct(private readonly ProjectInitializer $initializer = new ProjectInitializer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'Directory to set up; created when missing', '.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $argument = $input->getArgument('path');
        $argument = is_string($argument) && $argument !== '' ? $argument : '.';

        $result = $this->initializer->initialize($this->resolve($argument));

        $output->writeln('Created '.count($result->files).' files in '.OutputFormatter::escape($result->directory).':');
        foreach ($result->files as $file) {
            $output->writeln('  '.OutputFormatter::escape($file));
        }
        $output->writeln('');
        $output->writeln('Dates are interpreted in UTC unless you set `timezone` in verse.yaml.');
        $output->writeln('');
        $output->writeln('Next steps:');
        if ($result->directory !== realpath((string) getcwd())) {
            $output->writeln('  cd '.OutputFormatter::escape(self::shellWord($argument)));
        }
        $output->writeln('  verse build');

        return Command::SUCCESS;
    }

    /**
     * An absolute path for the argument: relative paths start at the working directory, and the existing part of
     * the path is canonicalised so `.`, `..` and symlinks do not leak into messages or temporary names.
     *
     * @throws FilesystemException
     */
    private function resolve(string $argument): string
    {
        $path = str_starts_with($argument, '/') ? $argument : (string) getcwd().'/'.$argument;

        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }

        $path = rtrim($path, '/');
        $name = basename($path);
        $parent = realpath(dirname($path));
        if ($parent === false || $name === '.' || $name === '..') {
            throw new FilesystemException('cannot initialize '.$argument.': the parent directory does not exist.');
        }

        return rtrim($parent, '/').'/'.$name;
    }

    private static function shellWord(string $path): string
    {
        return preg_match('#^[A-Za-z0-9_./@%+=:,-]+$#', $path) === 1 ? $path : escapeshellarg($path);
    }
}
