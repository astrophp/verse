<?php

declare(strict_types=1);

namespace Astro\Verse\Content;

use Astro\Verse\Error\ContentException;
use Astro\Verse\Error\FilesystemException;

/**
 * Lists the files of `public/` that are copied verbatim into the output.
 * Hidden files are skipped except under `public/.well-known/`.
 */
final class PublicScanner
{
    public function __construct(private readonly DirectoryLister $lister = new DirectoryLister) {}

    /**
     * @return list<PublicFile>
     */
    public function scan(string $root): array
    {
        $absolute = rtrim($root, '/').'/public';
        if (is_link($absolute)) {
            throw new FilesystemException('symbolic links are not supported. Replace the link with the real folder.', 'public');
        }
        if (! is_dir($absolute)) {
            return [];
        }

        return $this->walk($absolute, 'public', '', false);
    }

    /**
     * @return list<PublicFile>
     */
    private function walk(string $absolute, string $path, string $relative, bool $insideWellKnown): array
    {
        $files = [];
        $hidden = $insideWellKnown ? true : ($relative === '' ? ['.well-known'] : false);

        foreach ($this->lister->list($absolute, $path, $hidden) as $entry) {
            $isHidden = str_starts_with($entry->name, '.');
            if ($isHidden && ! $insideWellKnown && ! $entry->isDirectory) {
                continue;
            }

            $violation = NameRules::violation($entry->nfc, allowLeadingDot: true);
            if ($violation !== null) {
                throw new ContentException(
                    'the name cannot be served as a URL because '.$violation.'. Rename the file.',
                    $entry->path,
                );
            }

            $outputPath = $relative === '' ? $entry->nfc : $relative.'/'.$entry->nfc;
            if ($entry->isDirectory) {
                array_push($files, ...$this->walk($entry->absolute, $entry->path, $outputPath, $insideWellKnown || $isHidden));

                continue;
            }

            $files[] = new PublicFile($entry->path, $outputPath);
        }

        return $files;
    }
}
