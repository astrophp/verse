<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Support\Docs;

use RuntimeException;

/**
 * Reads the Markdown pages that are written by hand for people who use or contribute to Verse.
 * docs/research is local-only (git-excluded) and not part of them.
 */
final class Pages
{
    /**
     * @return list<string> absolute paths
     */
    public static function all(): array
    {
        $root = self::root();
        $pages = [];

        foreach (['README.md', 'CONTRIBUTING.md', 'CHANGELOG.md', 'ROADMAP.md', 'SECURITY.md'] as $name) {
            if (is_file($root.'/'.$name)) {
                $pages[] = $root.'/'.$name;
            }
        }

        $guides = glob($root.'/docs/*.md');
        foreach ($guides === false ? [] : $guides as $page) {
            $pages[] = $page;
        }

        return $pages;
    }

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function read(string $path): string
    {
        $text = file_get_contents($path);

        if ($text === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        return $text;
    }
}
