<?php

declare(strict_types=1);

namespace Astro\Verse\Error;

final class ContentException extends VerseException
{
    public function kind(): ErrorKind
    {
        return ErrorKind::Content;
    }
}
