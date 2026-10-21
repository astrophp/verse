<?php

declare(strict_types=1);

namespace Astro\Verse\Content;

use Astro\Verse\Error\ContentException;
use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Support\Unicode;

/**
 * Deterministic directory listing: sorted by NFC name, hidden entries skipped,
 * symbolic links rejected, names that differ only in Unicode normalization rejected.
 */
final class DirectoryLister
{
    /**
     * @param  bool|list<string>  $hidden  hidden entries to keep: false none, true all, or the listed names only
     * @return list<DirEntry>
     */
    public function list(string $absolute, string $path, bool|array $hidden = false): array
    {
        $names = scandir($absolute);
        if ($names === false) {
            throw new FilesystemException('cannot read the directory.', $path);
        }

        $names = array_values(array_filter(
            $names,
            static fn (string $name): bool => $name !== '.' && $name !== '..'
                && (! str_starts_with($name, '.') || $hidden === true || (is_array($hidden) && in_array($name, $hidden, true))),
        ));

        self::assertNoNfcDuplicates($names, $path);

        $entries = [];
        foreach ($names as $name) {
            $childAbsolute = $absolute.'/'.$name;
            $childPath = $path.'/'.$name;
            if (is_link($childAbsolute)) {
                throw new FilesystemException(
                    'symbolic links are not supported. Replace the link with the real file or folder.',
                    $childPath,
                );
            }
            $entries[] = new DirEntry($name, Unicode::nfc($name), $childAbsolute, $childPath, is_dir($childAbsolute));
        }

        usort($entries, static fn (DirEntry $a, DirEntry $b): int => strcmp($a->nfc, $b->nfc) ?: strcmp($a->name, $b->name));

        return $entries;
    }

    /**
     * @param  list<string>  $names  entry names of one folder
     */
    public static function assertNoNfcDuplicates(array $names, string $directory): void
    {
        $seen = [];
        foreach ($names as $name) {
            $normalized = Unicode::nfc($name);
            if (isset($seen[$normalized])) {
                throw new ContentException(
                    '"'.$seen[$normalized].'" and "'.$name.'" are the same name in Unicode normalization form C '
                    .'and would produce the same URL. Rename one of them.',
                    $directory.'/'.$name,
                );
            }
            $seen[$normalized] = $name;
        }
    }
}
