<?php

declare(strict_types=1);

namespace Astro\Verse\Build;

use Astro\Verse\Assets\ViteManifest;
use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Routing\OutputRegistry;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Checks a staged build before it is promoted: every claimed output exists, nothing unclaimed was written,
 * the theme's files and the entry pages are present.
 */
final class OutputValidator
{
    public static function validate(string $staging, OutputRegistry $registry, ViteManifest $manifest): void
    {
        $owners = $registry->owners();

        foreach ($owners as $path => $owner) {
            if (! is_file($staging.'/'.$path)) {
                throw new FilesystemException('the staged output is missing "'.$path.'" ('.$owner.').');
            }
        }

        foreach (['index.html', '404.html'] as $required) {
            if (! is_file($staging.'/'.$required)) {
                throw new FilesystemException('the staged output has no '.$required.'.');
            }
        }

        foreach ($manifest->files() as $file) {
            if (! is_file($staging.'/'.OutputRegistry::THEME_PREFIX.$file)) {
                throw new FilesystemException('the staged output is missing the theme file "'.$file.'".');
            }
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = substr((string) $file->getPathname(), strlen($staging) + 1);
            if (! isset($owners[$relative])) {
                throw new FilesystemException('the staged output contains "'.$relative.'", which no source claimed.');
            }
        }
    }
}
