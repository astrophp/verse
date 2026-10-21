<?php

declare(strict_types=1);

namespace Astro\Verse\Assets;

use Astro\Verse\Error\ThemeException;
use Astro\Verse\Routing\OutputRegistry;
use Astro\Verse\Routing\UrlGenerator;
use Astro\Verse\Theme\Theme;
use JsonException;

/**
 * A theme's prebuilt Vite output (`dist/.vite/manifest.json`): which files to copy into `assets/theme/` and
 * which tags `@verseAssets` prints. Only Vite's documented manifest format is read, so a
 * manifest from a future per-site build can take its place.
 *
 * Loading validates everything a build depends on: a readable manifest, every configured entry, every
 * referenced chunk and file, and no `dynamicImports` anywhere.
 */
final readonly class ViteManifest
{
    /**
     * @param  string  $name  the manifest as errors name it
     * @param  list<string>  $entries  manifest keys loaded on every page, in order
     * @param  array<string, array{file: string, css: list<string>, assets: list<string>, imports: list<string>}>  $chunks
     */
    private function __construct(private string $name, private array $entries, private array $chunks) {}

    /**
     * @throws ThemeException
     */
    public static function load(Theme $theme): self
    {
        $manifest = 'themes/'.$theme->name.'/dist/.vite/manifest.json';
        $error = static fn (string $detail): ThemeException => new ThemeException($detail, $manifest);

        $text = @file_get_contents($theme->manifestPath);
        if ($text === false) {
            throw $error('the Vite manifest was not found. Build the theme (npm run build in its folder) or restore dist/.');
        }
        try {
            $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $error('the Vite manifest is not valid JSON ('.$exception->getMessage().').');
        }
        if (! is_array($data)) {
            throw $error('the Vite manifest must be a JSON object.');
        }

        $chunks = [];
        foreach ($data as $key => $chunk) {
            $key = (string) $key;
            if (! is_array($chunk)) {
                throw $error('the chunk "'.$key.'" must be an object.');
            }
            if (array_key_exists('dynamicImports', $chunk)) {
                throw $error('the chunk "'.$key.'" has dynamicImports. Themes must not use code splitting or dynamic import().');
            }
            $file = $chunk['file'] ?? null;
            if (! is_string($file)) {
                throw $error('the chunk "'.$key.'" has no "file".');
            }

            $chunks[$key] = [
                'file' => $file,
                'css' => self::stringList($chunk, 'css', $key, $error),
                'assets' => self::stringList($chunk, 'assets', $key, $error),
                'imports' => self::stringList($chunk, 'imports', $key, $error),
            ];
        }

        $loaded = new self($manifest, $theme->entries, $chunks);
        foreach ($theme->entries as $entry) {
            if (! isset($chunks[$entry])) {
                throw $error('the entry "'.$entry.'" listed in theme.yaml is missing from the manifest.');
            }
        }
        foreach ($loaded->files() as $file) {
            if (! self::isRelativePath($file)) {
                throw $error('the file "'.$file.'" is not a plain relative path inside dist/.');
            }
            if (! is_file($theme->distPath.'/'.$file)) {
                throw $error('the file "'.$file.'" referenced by the manifest is missing from dist/.');
            }
        }

        return $loaded;
    }

    /**
     * Every file to copy, relative to the theme's `dist/`: for each entry its `file`, `css[]` and `assets[]`,
     * then the same for its transitive `imports`. Deduplicated, in first-seen order.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $files = [];
        $visited = [];
        foreach ($this->entries as $entry) {
            $this->collect($entry, $visited, $files);
        }

        return array_keys($files);
    }

    /**
     * Stylesheet links (for each entry: its own CSS `file`, its `css[]`, its imports' `css[]`) followed by the
     * module scripts of the JavaScript entries; base-path prefixed, each URL once.
     */
    public function assetTags(UrlGenerator $urls): string
    {
        $styles = [];
        $scripts = [];
        foreach ($this->entries as $entry) {
            $chunk = $this->chunks[$entry];
            if (self::isStylesheet($chunk['file'])) {
                $styles[$chunk['file']] = true;
            }
            foreach ($this->importedStyles($entry) as $style) {
                $styles[$style] = true;
            }
            if (self::isScript($chunk['file'])) {
                $scripts[$chunk['file']] = true;
            }
        }

        $tags = [];
        foreach (array_keys($styles) as $style) {
            $tags[] = '<link rel="stylesheet" href="'.htmlspecialchars($urls->asset(OutputRegistry::THEME_PREFIX.$style), ENT_QUOTES).'">';
        }
        foreach (array_keys($scripts) as $script) {
            $tags[] = '<script type="module" src="'.htmlspecialchars($urls->asset(OutputRegistry::THEME_PREFIX.$script), ENT_QUOTES).'"></script>';
        }

        return implode("\n", $tags);
    }

    /**
     * The `css[]` of a chunk and of its transitive imports (a CSS chunk's own `file` is not repeated here).
     *
     * @return list<string>
     */
    private function importedStyles(string $key): array
    {
        $styles = [];
        $visited = [];
        $walk = function (string $current) use (&$walk, &$styles, &$visited): void {
            if (isset($visited[$current])) {
                return;
            }
            $visited[$current] = true;
            $chunk = $this->chunks[$current];
            foreach ($chunk['css'] as $style) {
                $styles[$style] = true;
            }
            foreach ($chunk['imports'] as $import) {
                $walk($import);
            }
        };
        $walk($key);

        return array_keys($styles);
    }

    /**
     * @param  array<string, true>  $visited
     * @param  array<string, true>  $files
     */
    private function collect(string $key, array &$visited, array &$files): void
    {
        if (isset($visited[$key])) {
            return;
        }
        $visited[$key] = true;

        $chunk = $this->chunks[$key] ?? null;
        if ($chunk === null) {
            throw new ThemeException('the chunk "'.$key.'" is imported but missing from the manifest.', $this->name);
        }

        foreach ([$chunk['file'], ...$chunk['css'], ...$chunk['assets']] as $file) {
            $files[$file] = true;
        }
        foreach ($chunk['imports'] as $import) {
            $this->collect($import, $visited, $files);
        }
    }

    /**
     * @param  array<array-key, mixed>  $chunk
     * @param  callable(string): ThemeException  $error
     * @return list<string>
     */
    private static function stringList(array $chunk, string $field, string $key, callable $error): array
    {
        $value = $chunk[$field] ?? [];
        if (! is_array($value) || ! array_is_list($value)) {
            throw $error('the chunk "'.$key.'" has an invalid "'.$field.'" (expected a list of strings).');
        }
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw $error('the chunk "'.$key.'" has an invalid "'.$field.'" (expected a list of strings).');
            }
        }

        return $value;
    }

    private static function isRelativePath(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! in_array('..', explode('/', $path), true)
            && ! str_contains($path, '\\')
            && ! str_contains($path, "\0");
    }

    private static function isStylesheet(string $file): bool
    {
        return str_ends_with($file, '.css');
    }

    private static function isScript(string $file): bool
    {
        return str_ends_with($file, '.js') || str_ends_with($file, '.mjs');
    }
}
