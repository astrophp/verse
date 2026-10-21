<?php

declare(strict_types=1);

namespace Astro\Verse\Build;

use Astro\Verse\Error\FilesystemException;

/**
 * The few filesystem operations a build performs, behind an interface so tests can make one of them fail.
 */
interface Filesystem
{
    /**
     * @throws FilesystemException
     */
    public function rename(string $from, string $to): void;

    /**
     * Deletes a file, a link (never following it) or a directory tree; a missing path is not an error.
     *
     * @throws FilesystemException
     */
    public function removeTree(string $path): void;

    /**
     * @param  bool  $sync  flush the data to disk before returning (journals)
     * @param  bool  $exclusive  create the file, failing when anything (a link included) already has that name
     *
     * @throws FilesystemException
     */
    public function writeFile(string $path, string $contents, bool $sync = false, bool $exclusive = false): void;

    /**
     * @throws FilesystemException
     */
    public function copy(string $from, string $to): void;

    /**
     * Creates the directory and its parents; an existing directory is not an error.
     *
     * @throws FilesystemException
     */
    public function mkdir(string $path): void;
}
