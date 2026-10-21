<?php

declare(strict_types=1);

namespace Astro\Verse\Markdown;

use Astro\Verse\Error\ContentException;

/**
 * Rewrites the URL of a Markdown link or image found in one source file.
 */
interface LinkResolver
{
    /**
     * @param  string  $sourcePath  site-relative path of the file that contains the link
     * @param  string  $url  the URL exactly as written
     * @return string the URL to emit (query and fragment kept)
     *
     * @throws ContentException when the link cannot be resolved
     */
    public function resolve(string $sourcePath, string $url, bool $isImage): string;
}
