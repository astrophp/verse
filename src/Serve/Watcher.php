<?php

declare(strict_types=1);

namespace Astro\Verse\Serve;

use Astro\Verse\Config\ConfigLoader;
use Astro\Verse\Theme\ThemeRegistry;
use Astro\Verse\Theme\ThemeSettingsResolver;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Polls the inputs of a build for changes: `verse.yaml`, `content/`, `public/` and the whole root of the
 * selected theme. Each file is recorded with size and mtime, plus an md5 for text files of any size (an edit
 * that keeps size and mtime is still seen). Nothing is watched inside `.verse/`, `dist/`, `node_modules/`,
 * `vendor/` or dot entries, except `public/.well-known/` and the theme's `dist/.vite/`.
 */
final class Watcher
{
    private const array TEXT_EXTENSIONS = ['md', 'yaml', 'yml', 'php', 'css', 'js', 'json', 'html', 'txt', 'svg'];

    private const array SKIPPED_DIRECTORIES = ['node_modules', 'vendor'];

    /** @var ?array<string, array{int, int, ?string}> */
    private ?array $inventory = null;

    /** @var list<string> */
    private array $changes = [];

    private string $themeRoot;

    private ?string $configHash = null;

    private readonly string $root;

    private readonly ThemeRegistry $themes;

    public function __construct(string $root, ?ThemeRegistry $themes = null)
    {
        $this->root = rtrim($root, '/');
        $this->themes = $themes ?? ThemeRegistry::bundled();
        $this->themeRoot = $this->resolveThemeRoot();
    }

    /**
     * Scans once. The first call records the baseline and returns false; later calls return whether anything
     * differs from the previous scan.
     */
    public function poll(): bool
    {
        clearstatcache(true);

        $current = $this->scan();
        $previous = $this->inventory;
        $this->inventory = $current;
        $this->changes = [];
        if ($previous === null) {
            return false;
        }

        foreach ($current + $previous as $path => $_) {
            if (($current[$path] ?? null) !== ($previous[$path] ?? null)) {
                $this->changes[] = $path;
            }
        }
        sort($this->changes, SORT_STRING);

        return $this->changes !== [];
    }

    /**
     * Paths (relative to the site root; `theme:` prefix for theme files) that differed in the last `poll()`.
     *
     * @return list<string>
     */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * Re-reads which theme is selected. The inventory is left alone, so the next `poll()` reports every
     * difference since the previous one, including changes made while a build ran and the files of a new theme.
     */
    public function refreshTheme(): void
    {
        $this->themeRoot = $this->resolveThemeRoot();
    }

    public function themeRoot(): string
    {
        return $this->themeRoot;
    }

    /**
     * @return array<string, array{int, int, ?string}> path => [size, mtime, md5 of text files]
     */
    private function scan(): array
    {
        $config = $this->root.'/'.ConfigLoader::SITE_FILE;
        $hash = is_file($config) ? @md5_file($config) : false;
        $hash = $hash === false ? null : $hash;
        if ($hash !== $this->configHash) {
            $this->configHash = $hash;
            $this->themeRoot = $this->resolveThemeRoot();
        }

        $entries = [];
        $this->addEntry($entries, ConfigLoader::SITE_FILE, $config);
        $this->scanDirectory($entries, 'content', $this->root.'/content', []);
        $this->scanDirectory($entries, 'public', $this->root.'/public', ['.well-known']);
        $this->scanDirectory($entries, 'theme:', $this->themeRoot, ['dist/.vite']);

        return $entries;
    }

    /**
     * @param  array<string, array{int, int, ?string}>  $entries
     * @param  list<string>  $allowedDot  dot directories (relative to `$directory`) that are watched anyway
     */
    private function scanDirectory(array &$entries, string $prefix, string $directory, array $allowedDot, string $relative = ''): void
    {
        $names = @scandir($directory);
        if ($names === false) {
            return;
        }

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $relative === '' ? $name : $relative.'/'.$name;
            $absolute = $directory.'/'.$name;
            if (str_starts_with($name, '.') && ! in_array($path, $allowedDot, true)) {
                continue;
            }
            if (in_array($name, self::SKIPPED_DIRECTORIES, true) || $absolute === $this->root.'/.verse' || $absolute === $this->root.'/dist') {
                continue;
            }

            $key = $prefix === 'theme:' ? 'theme:'.$path : $prefix.'/'.$path;
            if (is_dir($absolute) && ! is_link($absolute)) {
                // directories are inputs too: an empty or invalid one can fail a build
                $entries[$key.'/'] = [-2, 0, null];
                $this->scanDirectory($entries, $prefix, $absolute, $allowedDot, $path);

                continue;
            }
            $this->addEntry($entries, $key, $absolute);
        }
    }

    /**
     * @param  array<string, array{int, int, ?string}>  $entries
     */
    private function addEntry(array &$entries, string $key, string $absolute): void
    {
        if (is_link($absolute)) {
            $stat = @lstat($absolute);
            $target = @readlink($absolute);
            if ($stat !== false) {
                // links are not followed: the link itself (and where it points) is the input
                $entries[$key] = [-1, $stat['mtime'], $target === false ? null : md5($target)];
            }

            return;
        }

        $size = @filesize($absolute);
        $mtime = @filemtime($absolute);
        if ($size === false || $mtime === false || ! is_file($absolute)) {
            return;
        }

        $hash = null;
        if (in_array(strtolower(pathinfo($absolute, PATHINFO_EXTENSION)), self::TEXT_EXTENSIONS, true)) {
            $computed = @md5_file($absolute);
            $hash = $computed === false ? null : $computed;
        }

        $entries[$key] = [$size, $mtime, $hash];
    }

    /**
     * The root of the theme `verse.yaml` selects. Falls back to the default theme name when the configuration
     * cannot be read, so fixing `verse.yaml` still triggers a rebuild.
     */
    private function resolveThemeRoot(): string
    {
        $name = ThemeSettingsResolver::DEFAULT_THEME;
        try {
            $text = @file_get_contents($this->root.'/'.ConfigLoader::SITE_FILE);
            $data = $text === false ? null : Yaml::parse($text);
            $theme = is_array($data) ? ($data['theme'] ?? null) : null;
            if (is_array($theme) && is_string($theme['name'] ?? null)) {
                $name = $theme['name'];
            }
        } catch (Throwable) {
            // keep the default
        }

        // Keep watching the selected theme's folder even while its theme.yaml is missing or the folder is gone,
        // so restoring it triggers a rebuild.
        return $this->themes->expectedDirectory($name)
            ?? $this->themes->expectedDirectory(ThemeSettingsResolver::DEFAULT_THEME)
            ?? ThemeRegistry::bundled()->expectedDirectory(ThemeSettingsResolver::DEFAULT_THEME)
            ?? $this->root.'/.verse/missing-theme';
    }
}
