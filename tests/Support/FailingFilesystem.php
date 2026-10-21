<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Support;

use Astro\Verse\Build\Filesystem;
use Astro\Verse\Build\LocalFilesystem;
use Astro\Verse\Error\FilesystemException;
use Closure;

/**
 * The real filesystem, except that renames (and, optionally, removals) matching a predicate fail.
 */
final class FailingFilesystem implements Filesystem
{
    private readonly LocalFilesystem $local;

    /** @var list<array{string, string}> */
    public array $renames = [];

    /**
     * @param  Closure(string, string): bool  $failRename  true makes `rename($from, $to)` throw
     * @param  ?Closure(string): bool  $failRemove  true makes `removeTree($path)` throw (nothing is deleted)
     */
    public function __construct(private readonly Closure $failRename, private readonly ?Closure $failRemove = null)
    {
        $this->local = new LocalFilesystem;
    }

    public function rename(string $from, string $to): void
    {
        $this->renames[] = [$from, $to];
        if (($this->failRename)($from, $to)) {
            throw new FilesystemException('cannot rename '.$from.' to '.$to.': injected failure');
        }
        $this->local->rename($from, $to);
    }

    public function removeTree(string $path): void
    {
        if ($this->failRemove !== null && ($this->failRemove)($path)) {
            throw new FilesystemException('cannot delete '.$path.': injected failure');
        }
        $this->local->removeTree($path);
    }

    public function writeFile(string $path, string $contents, bool $sync = false, bool $exclusive = false): void
    {
        $this->local->writeFile($path, $contents, $sync, $exclusive);
    }

    public function copy(string $from, string $to): void
    {
        $this->local->copy($from, $to);
    }

    public function mkdir(string $path): void
    {
        $this->local->mkdir($path);
    }
}
