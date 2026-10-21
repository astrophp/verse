<?php

declare(strict_types=1);

namespace Astro\Verse\Build;

use Astro\Verse\Error\FilesystemException;

final class LocalFilesystem implements Filesystem
{
    public function rename(string $from, string $to): void
    {
        if (! @rename($from, $to)) {
            throw $this->failure('rename '.$from.' to '.$to);
        }
    }

    public function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (! @unlink($path)) {
                throw $this->failure('delete '.$path);
            }

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            throw $this->failure('read directory '.$path);
        }
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path.'/'.$entry);
            }
        }
        if (! @rmdir($path)) {
            throw $this->failure('delete directory '.$path);
        }
    }

    public function writeFile(string $path, string $contents, bool $sync = false, bool $exclusive = false): void
    {
        $handle = @fopen($path, $exclusive ? 'xb' : 'wb');
        if ($handle === false) {
            throw $this->failure('write '.$path);
        }

        $written = @fwrite($handle, $contents);
        $flushed = $written === strlen($contents) && fflush($handle) && (! $sync || (function_exists('fsync') && fsync($handle)));
        fclose($handle);
        if (! $flushed) {
            throw $this->failure('write '.$path);
        }
    }

    public function copy(string $from, string $to): void
    {
        if (! @copy($from, $to)) {
            throw $this->failure('copy '.$from.' to '.$to);
        }
    }

    public function mkdir(string $path): void
    {
        if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
            throw $this->failure('create directory '.$path);
        }
    }

    private function failure(string $action): FilesystemException
    {
        $reason = error_get_last()['message'] ?? 'unknown error';

        return new FilesystemException('cannot '.$action.': '.$reason);
    }
}
