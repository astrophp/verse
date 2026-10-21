<?php

declare(strict_types=1);

namespace Astro\Verse\Console;

use Astro\Verse\Error\VerseException;
use Astro\Verse\Verse;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class Application extends SymfonyApplication
{
    public const int EXIT_ERROR = 1;

    public const int EXIT_UNEXPECTED = 2;

    public function __construct()
    {
        parent::__construct('Verse', Verse::version());

        $this->addCommand(new BuildCommand);
        $this->addCommand(new CheckCommand);
        $this->addCommand(new InitCommand);
        $this->addCommand(new PreviewBuildCommand);
        $this->addCommand(new ServeCommand);
    }

    public function getLongVersion(): string
    {
        return 'Verse '.Verse::version();
    }

    /**
     * Exit 1 for expected failures (`error: …`), exit 2 for anything else (trace with -v).
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRun($input, $output);
        } catch (VerseException $exception) {
            $this->errorOutput($output)->writeln('<error>error: '.$exception->getMessage().'</error>');

            return self::EXIT_ERROR;
        } catch (ExceptionInterface $exception) {
            // Usage errors (unknown command, bad option): rendered by Symfony, exit 1.
            throw $exception;
        } catch (Throwable $exception) {
            $error = $this->errorOutput($output);
            $error->writeln('<error>error: unexpected failure: '.$exception->getMessage().'</error>');
            if ($output->isVerbose()) {
                $error->writeln($exception->getTraceAsString());
            }

            return self::EXIT_UNEXPECTED;
        }
    }

    private function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
