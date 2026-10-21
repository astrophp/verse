<?php

declare(strict_types=1);

namespace Astro\Verse\Init;

use Astro\Verse\Build\LocalFilesystem;
use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Verse;
use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Writes the starter site from `resources/scaffold/` for `verse init`.
 *
 * A missing target is built in a sibling temporary directory and renamed into place; an existing target
 * (empty, or holding only `.git` / `.DS_Store`) gets exclusively created entries, and on failure exactly
 * those are removed again.
 */
final class ProjectInitializer
{
    private const int MAX_LISTED_ENTRIES = 10;

    private const int TEMPORARY_ATTEMPTS = 3;

    /** @var list<string> entries an existing target may contain */
    private const array IGNORED_ENTRIES = ['.DS_Store', '.git'];

    /**
     * @param  ?Closure(string, string): void  $beforeCreate  called with the site-relative and the absolute path
     *                                                        before each entry is created (a test seam)
     * @param  ?Closure(): string  $suffix  produces the random part of a temporary directory name (a test seam)
     */
    public function __construct(
        private readonly ?string $scaffoldPath = null,
        private readonly ?Closure $beforeCreate = null,
        private readonly ?Closure $suffix = null,
    ) {}

    /**
     * @param  string  $target  absolute path of the site folder; it may not exist yet
     *
     * @throws FilesystemException
     */
    public function initialize(string $target): InitResult
    {
        $scaffold = $this->scaffold();

        if (file_exists($target) || is_link($target)) {
            if (! is_dir($target)) {
                throw new FilesystemException('cannot initialize '.$target.': it exists and is not a directory.');
            }
            $this->assertCanInitialize($target);
            $this->writeInto($target, $scaffold);

            return new InitResult($target, array_keys($scaffold), false);
        }

        $parent = dirname($target);
        if (! is_dir($parent)) {
            throw new FilesystemException('cannot initialize '.$target.': the parent directory '.$parent.' does not exist.');
        }
        if (! is_writable($parent)) {
            throw new FilesystemException('cannot initialize '.$target.': the parent directory '.$parent.' is not writable.');
        }

        $this->writeViaTemporaryDirectory($target, $scaffold);

        return new InitResult($target, array_keys($scaffold), true);
    }

    /**
     * @throws FilesystemException
     */
    private function assertCanInitialize(string $target): void
    {
        $entries = scandir($target);
        if ($entries === false) {
            throw new FilesystemException('cannot initialize '.$target.': the directory cannot be read.');
        }

        $others = array_values(array_filter(
            $entries,
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..' && ! in_array($entry, self::IGNORED_ENTRIES, true),
        ));
        if ($others !== []) {
            sort($others, SORT_STRING);
            $listed = array_slice($others, 0, self::MAX_LISTED_ENTRIES);
            $more = count($others) - count($listed);

            throw new FilesystemException(sprintf(
                'cannot initialize %s: the directory is not empty (found %s%s). Nothing was changed.',
                $target,
                implode(', ', $listed),
                $more > 0 ? ', and '.$more.' more' : '',
            ));
        }

        if (! is_writable($target)) {
            throw new FilesystemException('cannot initialize '.$target.': the directory is not writable.');
        }
    }

    /**
     * @param  array<string, string>  $scaffold
     *
     * @throws FilesystemException
     */
    private function writeViaTemporaryDirectory(string $target, array $scaffold): void
    {
        $temporary = $this->createTemporaryDirectory($target);
        $filesystem = new LocalFilesystem;

        try {
            // the temporary directory is removed as a whole, so nothing needs to be tracked here
            $this->writeInto($temporary, $scaffold, cleanUp: false);
            $filesystem->rename($temporary, $target);
        } catch (Throwable $exception) {
            try {
                $filesystem->removeTree($temporary);
            } catch (Throwable) {
                throw new FilesystemException($exception->getMessage().' The temporary directory '.$temporary.' could not be removed.', previous: $exception);
            }

            throw $exception;
        }
    }

    /**
     * Creates a fresh sibling directory of the target. A name that is already taken is never touched: another
     * name is tried (three attempts), and only a directory this run created is ever removed.
     *
     * @throws FilesystemException
     */
    private function createTemporaryDirectory(string $target): string
    {
        for ($attempt = 0; $attempt < self::TEMPORARY_ATTEMPTS; $attempt++) {
            $random = $this->suffix === null ? bin2hex(random_bytes(4)) : ($this->suffix)();
            $temporary = dirname($target).'/.'.basename($target).'.verse-init-'.$random;

            if (@mkdir($temporary, 0775)) {
                return $temporary;
            }
            if (! file_exists($temporary) && ! is_link($temporary)) {
                throw $this->failure('create directory '.$temporary);
            }
        }

        throw new FilesystemException('cannot initialize '.$target.': no free temporary directory name was found beside it. Nothing was changed.');
    }

    /**
     * Creates every scaffold entry below `$root`, which must not contain any of them yet.
     *
     * @param  array<string, string>  $scaffold  relative path => contents
     *
     * @throws FilesystemException
     */
    private function writeInto(string $root, array $scaffold, bool $cleanUp = true): void
    {
        /** @var list<string> $directories */
        $directories = [];
        /** @var list<string> $files */
        $files = [];
        $known = [];

        try {
            foreach ($scaffold as $relative => $contents) {
                $parts = explode('/', $relative);
                array_pop($parts);
                $path = '';
                foreach ($parts as $part) {
                    $path .= ($path === '' ? '' : '/').$part;
                    if (isset($known[$path])) {
                        continue;
                    }
                    $this->hook($path, $root.'/'.$path);
                    if (! @mkdir($root.'/'.$path, 0775)) {
                        throw $this->failure('create directory '.$root.'/'.$path);
                    }
                    $directories[] = $root.'/'.$path;
                    $known[$path] = true;
                }

                $this->hook($relative, $root.'/'.$relative);
                $handle = @fopen($root.'/'.$relative, 'xb');
                if ($handle === false) {
                    throw $this->failure('create '.$root.'/'.$relative);
                }
                $files[] = $root.'/'.$relative;
                $written = @fwrite($handle, $contents);
                $closed = fclose($handle);
                if ($written !== strlen($contents) || ! $closed) {
                    throw $this->failure('write '.$root.'/'.$relative);
                }
            }
        } catch (FilesystemException $exception) {
            if (! $cleanUp) {
                throw $exception;
            }

            $leftovers = $this->remove($files, $directories);
            throw new FilesystemException(
                $exception->detail.' Removed the files created so far.'.($leftovers === [] ? '' : ' Could not remove: '.implode(', ', $leftovers).'.'),
                previous: $exception,
            );
        }
    }

    /**
     * Removes what this run created, files first, then directories, newest first.
     *
     * @param  list<string>  $files
     * @param  list<string>  $directories
     * @return list<string> paths that could not be removed
     */
    private function remove(array $files, array $directories): array
    {
        $leftovers = [];
        foreach (array_reverse($files) as $file) {
            if (! @unlink($file)) {
                $leftovers[] = $file;
            }
        }
        foreach (array_reverse($directories) as $directory) {
            if (! @rmdir($directory)) {
                $leftovers[] = $directory;
            }
        }

        return $leftovers;
    }

    private function hook(string $relative, string $absolute): void
    {
        if ($this->beforeCreate !== null) {
            ($this->beforeCreate)($relative, $absolute);
        }
    }

    private function failure(string $action): FilesystemException
    {
        return new FilesystemException('cannot '.$action.': '.(error_get_last()['message'] ?? 'unknown error').'.');
    }

    /**
     * @return array<string, string> relative path => contents, sorted by path
     *
     * @throws FilesystemException
     */
    private function scaffold(): array
    {
        $root = $this->scaffoldPath ?? Verse::packageRoot().'/resources/scaffold';
        if (! is_dir($root)) {
            throw new FilesystemException('the project template '.$root.' is missing.');
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = (string) $file->getPathname();
            if ($file->getFilename() === '.DS_Store' || ! $file->isFile()) {
                continue;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new FilesystemException('cannot read '.$path.'.');
            }
            $files[substr($path, strlen($root) + 1)] = $contents;
        }
        ksort($files, SORT_STRING);

        return $files;
    }
}
