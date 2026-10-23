<?php

declare(strict_types=1);

namespace Astro\Verse\Build;

use Astro\Verse\Error\FilesystemException;

/**
 * Refuses unsafe layouts before anything is written or deleted: managed directories that are
 * symbolic links, and an output directory that overlaps the sources. Paths are normalised lexically and
 * compared by the real location of their existing ancestors.
 */
final class PathGuard
{
    /** @var list<string> every private path below the site root that a build deletes, writes or renames in */
    private const array MANAGED = [
        '.verse',
        '.verse/lock',
        '.verse/cache',
        '.verse/cache/views',
        '.verse/cache/views-preview',
        '.verse/staging',
        '.verse/previous',
        '.verse/transactions',
        '.verse/preview',
        'dist',
        'content',
        'public',
        'theme',
    ];

    /**
     * Rejects a symbolic link at the state directory and at every managed path that exists. Runs before the
     * state directory is created, so nothing outside the site can be reached through a link.
     */
    public static function assertState(string $root): void
    {
        $root = rtrim($root, '/');
        foreach (self::MANAGED as $name) {
            if (is_link($root.'/'.$name)) {
                throw new FilesystemException('symbolic links are not supported. Replace the link with the real folder.', $name);
            }
        }
    }

    public static function assertSafe(BuildContext $context): void
    {
        self::assertState($context->root);
        self::assertSources($context);
    }

    /**
     * The checks that need no private state: symbolic links at `content`, `public`, `theme` and `dist`, and an output
     * directory that is relative, a link, contains the site, overlaps the sources or sits in `.verse/`.
     * `verse check` runs only this, so it never has to look at `.verse/`.
     */
    public static function assertSources(BuildContext $context): void
    {
        $site = rtrim($context->root, '/');
        foreach (['content', 'public', 'theme', 'dist'] as $name) {
            if (is_link($site.'/'.$name)) {
                throw new FilesystemException('symbolic links are not supported. Replace the link with the real folder.', $name);
            }
        }

        $raw = $context->outputDir;
        if ($raw === '' || ! str_starts_with($raw, '/')) {
            throw new FilesystemException('the output directory "'.$raw.'" must be an absolute path.');
        }
        $output = self::normalize($raw);
        if (is_link($output)) {
            throw new FilesystemException('the output directory '.$output.' is a symbolic link. Replace it with a real folder or choose another output.');
        }

        $root = self::resolve($context->root);
        $real = self::resolve($output);

        if ($real === $root || self::inside($root, $real)) {
            throw new FilesystemException('the output directory '.$output.' contains the site; choose a folder inside it, such as dist.');
        }
        foreach (['content', 'public', 'theme'] as $source) {
            $path = self::resolve($root.'/'.$source);
            if (self::inside($real, $path) || self::inside($path, $real)) {
                throw new FilesystemException('the output directory '.$output.' overlaps the '.$source.'/ folder. Choose another output.');
            }
        }

        $state = $root.'/.verse';
        if ($context->mode === BuildMode::Preview) {
            if ($real === $state.'/preview' || ! self::inside($real, $state.'/preview')) {
                throw new FilesystemException('preview output must be inside .verse/preview/, got '.$output.'.');
            }
        } elseif (self::inside($real, $state)) {
            throw new FilesystemException('the output directory '.$output.' is inside .verse/, which is Verse\'s private state. Choose another output.');
        }
    }

    /**
     * Collapses `.`, `..` and repeated slashes of an absolute path (relative paths are returned unchanged).
     */
    public static function normalize(string $path): string
    {
        if (! str_starts_with($path, '/')) {
            return $path;
        }

        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }

    /**
     * The normalised path with its deepest existing ancestor replaced by that ancestor's real path.
     */
    public static function resolve(string $path): string
    {
        $path = self::normalize($path);
        $tail = [];
        $current = $path;
        while ($current !== '/' && ! file_exists($current) && ! is_link($current)) {
            array_unshift($tail, basename($current));
            $current = dirname($current);
        }

        $real = realpath($current);
        $base = $real === false ? $current : $real;

        return rtrim($base, '/').($tail === [] ? '' : '/'.implode('/', $tail));
    }

    /**
     * Whether `$path` is `$directory` or lies inside it (plain path comparison).
     */
    public static function inside(string $path, string $directory): bool
    {
        $directory = rtrim($directory, '/');

        return $path === $directory || str_starts_with($path, $directory.'/');
    }
}
