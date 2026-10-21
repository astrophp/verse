<?php

declare(strict_types=1);

namespace Astro\Verse\Error;

final class ConfigException extends VerseException
{
    public function kind(): ErrorKind
    {
        return ErrorKind::Config;
    }
}
