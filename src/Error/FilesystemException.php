<?php

declare(strict_types=1);

namespace Astro\Verse\Error;

final class FilesystemException extends VerseException
{
    public function kind(): ErrorKind
    {
        return ErrorKind::Filesystem;
    }
}
