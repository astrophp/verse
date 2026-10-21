<?php

declare(strict_types=1);

namespace Astro\Verse\Error;

use RuntimeException;
use Throwable;

/**
 * Base class of every expected, user-facing failure.
 *
 * The message has the form
 * `content error in content/articles/x.md, field "date": expected ..., got "soon".`
 * where the location parts (file, field, route) are optional.
 */
abstract class VerseException extends RuntimeException
{
    /**
     * @param  ?string  $sourceFile  site-relative file the error refers to (not `Exception::$file`)
     */
    public function __construct(
        public readonly string $detail,
        public readonly ?string $sourceFile = null,
        public readonly ?string $field = null,
        public readonly ?string $route = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(self::compose($this->kind(), $detail, $sourceFile, $field, $route), 0, $previous);
    }

    abstract public function kind(): ErrorKind;

    private static function compose(ErrorKind $kind, string $detail, ?string $file, ?string $field, ?string $route): string
    {
        $location = [];
        if ($file !== null) {
            $location[] = 'in '.$file;
        }
        if ($field !== null) {
            $location[] = 'field "'.$field.'"';
        }
        if ($route !== null) {
            $location[] = 'route '.$route;
        }

        $prefix = $kind->value.' error';
        if ($location !== []) {
            $prefix .= ' '.implode(', ', $location);
        }

        return $prefix.': '.$detail;
    }
}
