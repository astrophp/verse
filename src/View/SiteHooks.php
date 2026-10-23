<?php

declare(strict_types=1);

namespace Astro\Verse\View;

use Astro\Verse\Content\DirectoryLister;
use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Error\ThemeException;

/**
 * Validates the website's `theme/` folder, of which only `theme/hooks/` is read in this version.
 */
final class SiteHooks
{
    private const string FOLDER = 'theme';

    /**
     * @return ?string the absolute `theme/hooks` directory, null when the website has none
     *
     * @throws ThemeException|FilesystemException for anything in `theme/` that is not a hook
     */
    public static function load(string $root): ?string
    {
        $root = rtrim($root, '/');
        $theme = $root.'/'.self::FOLDER;

        self::assertNotLink($theme, self::FOLDER);
        if (! file_exists($theme)) {
            return null;
        }
        if (! is_dir($theme)) {
            throw new ThemeException('"theme" must be a folder. Remove the file, or turn it into a folder holding hooks/.', self::FOLDER);
        }

        $lister = new DirectoryLister;
        $hooks = null;
        foreach ($lister->list($theme, self::FOLDER) as $entry) {
            if ($entry->name !== 'hooks') {
                throw new ThemeException('only theme/hooks/ is supported in this version. Move or remove this entry.', $entry->path);
            }
            if (! $entry->isDirectory) {
                throw new ThemeException('"theme/hooks" must be a folder holding hook views such as head.blade.php.', $entry->path);
            }
            $hooks = $entry;
        }
        if ($hooks === null) {
            return null;
        }

        $known = array_map(static fn (Hook $hook): string => $hook->value.'.blade.php', Hook::cases());
        foreach ($lister->list($hooks->absolute, $hooks->path) as $entry) {
            if ($entry->isDirectory || ! in_array($entry->name, $known, true) || ! is_file($entry->absolute)) {
                throw new ThemeException(
                    'unknown hook file. Name it <hook>.blade.php; known hooks: '.Hook::list().'.',
                    $entry->path,
                );
            }
        }

        return $hooks->absolute;
    }

    private static function assertNotLink(string $absolute, string $path): void
    {
        if (is_link($absolute)) {
            throw new FilesystemException('symbolic links are not supported. Replace the link with the real folder.', $path);
        }
    }
}
