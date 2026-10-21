<?php

declare(strict_types=1);

namespace Astro\Verse;

use Composer\InstalledVersions;

final class Verse
{
    public const string PACKAGE = 'astrophp/verse';

    /**
     * The installed version as Composer recorded it: `0.2.0` for the tag `v0.2.0`, `dev-main
     * (f8d2498)` for a checkout of a branch, `unknown` when Composer has no version (an archive
     * installed without `COMPOSER_ROOT_VERSION`).
     */
    public static function version(): string
    {
        if (! InstalledVersions::isInstalled(self::PACKAGE)) {
            return self::formatVersion(null, null);
        }

        return self::formatVersion(
            InstalledVersions::getPrettyVersion(self::PACKAGE),
            InstalledVersions::getReference(self::PACKAGE),
        );
    }

    /**
     * Turns Composer's pretty version and source reference into the version Verse prints.
     */
    public static function formatVersion(?string $pretty, ?string $reference): string
    {
        // Composer's version when it cannot guess one (no tag, no VCS, no COMPOSER_ROOT_VERSION).
        if ($pretty === null || $pretty === '' || str_ends_with($pretty, '+no-version-set')) {
            return 'unknown';
        }

        if (str_starts_with($pretty, 'dev-') || str_ends_with($pretty, '-dev')) {
            return $reference === null || $reference === '' ? $pretty : $pretty.' ('.substr($reference, 0, 7).')';
        }

        return preg_replace('/^v(?=\d)/', '', $pretty) ?? $pretty;
    }

    public static function packageRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Directory that holds the bundled themes (`themes/<name>/`).
     */
    public static function themesPath(): string
    {
        return self::packageRoot().'/themes';
    }
}
