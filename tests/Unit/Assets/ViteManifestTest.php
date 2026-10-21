<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Assets;

use Astro\Verse\Assets\ViteManifest;
use Astro\Verse\Error\ThemeException;
use Astro\Verse\Routing\UrlGenerator;
use Astro\Verse\Tests\Support\TestCase;
use Astro\Verse\Theme\Theme;
use Astro\Verse\Theme\ThemeRegistry;
use Astro\Verse\Theme\ThemeSettings;
use PHPUnit\Framework\Attributes\Test;

final class ViteManifestTest extends TestCase
{
    /**
     * @param  array<string, mixed>|string  $manifest  decoded manifest or raw text
     * @param  list<string>  $entries
     * @param  list<string>  $files  files present in dist/
     */
    private function theme(array|string $manifest, array $entries, array $files): Theme
    {
        $contents = ['dist/.vite/manifest.json' => is_string($manifest) ? $manifest : json_encode($manifest, JSON_THROW_ON_ERROR)];
        foreach ($files as $file) {
            $contents['dist/'.$file] = 'x';
        }
        $root = $this->makeSite($contents);

        return new Theme('fixture', $root, $root.'/views', $root.'/dist', $root.'/dist/.vite/manifest.json', $entries, [], new ThemeSettings('fixture'));
    }

    private function assertThemeError(callable $callback, string ...$parts): void
    {
        try {
            $callback();
        } catch (ThemeException $exception) {
            $this->assertStringContainsString('themes/fixture/dist/.vite/manifest.json', $exception->getMessage());
            foreach ($parts as $part) {
                $this->assertStringContainsString($part, $exception->getMessage());
            }

            return;
        }
        $this->fail('Expected a ThemeException');
    }

    #[Test]
    public function a_css_entry_contributes_its_own_file_and_its_assets(): void
    {
        $theme = $this->theme(
            ['src/app.css' => ['file' => 'app-1.css', 'assets' => ['font-1.woff2']], 'src/app.js' => ['file' => 'app-1.js']],
            ['src/app.css', 'src/app.js'],
            ['app-1.css', 'font-1.woff2', 'app-1.js'],
        );
        $manifest = ViteManifest::load($theme);

        $this->assertSame(['app-1.css', 'font-1.woff2', 'app-1.js'], $manifest->files());
        $this->assertSame(
            '<link rel="stylesheet" href="/assets/theme/app-1.css">'."\n".'<script type="module" src="/assets/theme/app-1.js"></script>',
            $manifest->assetTags(new UrlGenerator),
        );
    }

    #[Test]
    public function tags_are_base_path_prefixed(): void
    {
        $theme = $this->theme(['a.css' => ['file' => 'a.css']], ['a.css'], ['a.css']);

        $this->assertSame(
            '<link rel="stylesheet" href="/notes/assets/theme/a.css">',
            ViteManifest::load($theme)->assetTags(new UrlGenerator('/notes/')),
        );
    }

    #[Test]
    public function a_js_entry_brings_css_and_transitive_imports_even_with_a_cycle(): void
    {
        $theme = $this->theme(
            [
                'main.js' => ['file' => 'main.js', 'css' => ['main.css'], 'imports' => ['_a']],
                '_a' => ['file' => 'a.js', 'css' => ['a.css'], 'assets' => ['a.png'], 'imports' => ['_b']],
                '_b' => ['file' => 'b.js', 'imports' => ['_a']],
            ],
            ['main.js'],
            ['main.js', 'main.css', 'a.js', 'a.css', 'a.png', 'b.js'],
        );
        $manifest = ViteManifest::load($theme);

        $this->assertSame(['main.js', 'main.css', 'a.js', 'a.css', 'a.png', 'b.js'], $manifest->files());
        $this->assertSame(
            '<link rel="stylesheet" href="/assets/theme/main.css">'."\n"
            .'<link rel="stylesheet" href="/assets/theme/a.css">'."\n"
            .'<script type="module" src="/assets/theme/main.js"></script>',
            $manifest->assetTags(new UrlGenerator),
        );
    }

    #[Test]
    public function every_url_appears_once_when_entries_share_files(): void
    {
        $theme = $this->theme(
            [
                'one.js' => ['file' => 'one.js', 'css' => ['shared.css'], 'imports' => ['_s']],
                'two.js' => ['file' => 'two.js', 'css' => ['shared.css'], 'imports' => ['_s']],
                '_s' => ['file' => 's.js', 'css' => ['shared.css']],
            ],
            ['one.js', 'two.js'],
            ['one.js', 'two.js', 'shared.css', 's.js'],
        );
        $manifest = ViteManifest::load($theme);

        $this->assertSame(['one.js', 'shared.css', 's.js', 'two.js'], $manifest->files());
        $tags = $manifest->assetTags(new UrlGenerator);
        $this->assertSame(1, substr_count($tags, 'shared.css'));
        $this->assertSame(2, substr_count($tags, '<script'));
    }

    #[Test]
    public function dynamic_imports_are_rejected_anywhere_in_the_manifest(): void
    {
        $theme = $this->theme(
            ['a.js' => ['file' => 'a.js'], 'unused.js' => ['file' => 'u.js', 'dynamicImports' => []]],
            ['a.js'],
            ['a.js', 'u.js'],
        );

        $this->assertThemeError(fn () => ViteManifest::load($theme), 'unused.js', 'dynamicImports');
    }

    #[Test]
    public function a_missing_manifest_entry_chunk_or_file_is_a_theme_error(): void
    {
        $this->assertThemeError(
            fn () => ViteManifest::load($this->theme('', ['a.js'], [])),
            'not valid JSON',
        );
        $this->assertThemeError(
            fn () => ViteManifest::load($this->theme(['a.js' => ['file' => 'a.js']], ['b.js'], ['a.js'])),
            'entry "b.js"',
        );
        $this->assertThemeError(
            fn () => ViteManifest::load($this->theme(['a.js' => ['file' => 'a.js']], ['a.js'], [])),
            'a.js',
            'missing from dist/',
        );
        $this->assertThemeError(
            fn () => ViteManifest::load($this->theme(['a.js' => ['file' => 'a.js', 'css' => ['gone.css']]], ['a.js'], ['a.js'])),
            'gone.css',
        );
        $this->assertThemeError(
            fn () => ViteManifest::load($this->theme(['a.js' => ['file' => 'a.js', 'imports' => ['_nope']]], ['a.js'], ['a.js'])),
            '_nope',
        );
        $this->assertThemeError(
            fn () => ViteManifest::load($this->theme(['a.js' => ['file' => '../a.js']], ['a.js'], [])),
            'plain relative path',
        );
    }

    #[Test]
    public function a_missing_manifest_file_is_a_theme_error(): void
    {
        $root = $this->makeSite(['dist/a.js' => 'x']);
        $theme = new Theme('fixture', $root, $root.'/views', $root.'/dist', $root.'/dist/.vite/manifest.json', ['a.js'], [], new ThemeSettings('fixture'));

        $this->assertThemeError(fn () => ViteManifest::load($theme), 'was not found');
    }

    #[Test]
    public function the_bundled_default_theme_loads(): void
    {
        $theme = ThemeRegistry::bundled()->find('default');
        $manifest = ViteManifest::load($theme);

        $this->assertCount(6, $manifest->files());
        $this->assertSame(1, substr_count($manifest->assetTags(new UrlGenerator), '<link rel="stylesheet"'));
        $this->assertSame(1, substr_count($manifest->assetTags(new UrlGenerator), '<script type="module"'));
    }
}
