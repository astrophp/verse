<?php

declare(strict_types=1);

namespace Astro\Verse\Build;

use Astro\Verse\Error\FilesystemException;

/**
 * Serializes builds of one site: an exclusive, non-blocking `flock` on `.verse/lock` that lives as long as the
 * handle does (and is released by the operating system if the process dies).
 */
final class BuildLock
{
    /**
     * @param  resource  $handle
     */
    private function __construct(private $handle) {}

    public static function acquire(string $path): self
    {
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new FilesystemException('cannot open the build lock '.$path.'.');
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new FilesystemException('another Verse build is running for this site. Wait for it to finish, then try again.');
        }

        return new self($handle);
    }

    public function release(): void
    {
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
    }
}
