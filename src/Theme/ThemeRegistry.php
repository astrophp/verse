<?php

declare(strict_types=1);

namespace Astro\Verse\Theme;

use Astro\Verse\Error\ThemeException;
use Astro\Verse\Verse;

/**
 * Finds themes by name in an ordered list of root directories (the first root that has the theme wins).
 */
final class ThemeRegistry
{
    public const string NAME_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    /** @var array<string, Theme> */
    private array $loaded = [];

    /**
     * @param  list<string>  $roots  directories that contain `<name>/theme.yaml`
     */
    public function __construct(
        private readonly array $roots,
        private readonly ThemeLoader $loader = new ThemeLoader,
    ) {}

    /**
     * The themes shipped in the package.
     */
    public static function bundled(): self
    {
        return new self([Verse::themesPath()]);
    }

    /**
     * @throws ThemeException when the theme does not exist or its theme.yaml is invalid
     */
    public function find(string $name): Theme
    {
        if (isset($this->loaded[$name])) {
            return $this->loaded[$name];
        }

        $directory = $this->directoryOf($name);
        if ($directory === null) {
            throw new ThemeException('theme "'.$name.'" was not found. Available themes: '.implode(', ', $this->names()).'.');
        }

        return $this->loaded[$name] = $this->loader->load($directory, $name);
    }

    /**
     * Names of the available themes, sorted.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->roots as $root) {
            foreach (scandir($root) ?: [] as $entry) {
                if ($this->directoryOf($entry) !== null) {
                    $names[$entry] = true;
                }
            }
        }
        // numeric directory names ("123") become int array keys
        $names = array_map(strval(...), array_keys($names));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Where a theme of that name lives or would live: its directory when it is valid, else the first root's
     * directory of that name (even while `theme.yaml` is missing or the folder is gone). Null for an invalid name.
     */
    public function expectedDirectory(string $name): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || $this->roots === []) {
            return null;
        }
        foreach ($this->roots as $root) {
            if (is_dir($root.'/'.$name)) {
                return $root.'/'.$name;
            }
        }

        return $this->roots[0].'/'.$name;
    }

    /**
     * The directory of a theme, null when no root has a valid theme of that name.
     */
    public function directoryOf(string $name): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return null;
        }

        foreach ($this->roots as $root) {
            if (is_file($root.'/'.$name.'/'.ThemeLoader::FILE)) {
                return $root.'/'.$name;
            }
        }

        return null;
    }
}
