<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Build;

use Astro\Verse\Error\ConfigException;
use Astro\Verse\Error\ThemeException;
use Astro\Verse\Tests\Support\TestCase;
use Astro\Verse\Theme\ThemeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Nothing is hard-coded to the `default` theme: a second theme, selected by name, renders with its own views and assets.
 */
final class ThemeSelectionTest extends TestCase
{
    private const array ROLES = ['home', 'page', 'article', 'listing', 'tags', 'not-found'];

    /**
     * A complete minimal theme as a map of path => contents (below the themes root).
     *
     * @return array<string, string>
     */
    private static function paper(): array
    {
        $files = [
            'paper/theme.yaml' => "name: paper\ndescription: Fixture theme.\nentries: [src/main.css, src/main.js]\nsettings: {toc: true}\n",
            'paper/dist/.vite/manifest.json' => json_encode([
                'src/main.css' => ['file' => 'main-abc.css', 'assets' => ['paper-font.woff2']],
                'src/main.js' => ['file' => 'main-def.js'],
            ], JSON_THROW_ON_ERROR),
            'paper/dist/main-abc.css' => 'body{}',
            'paper/dist/paper-font.woff2' => 'font',
            'paper/dist/main-def.js' => 'console.log(1)',
            'paper/views/layouts/base.blade.php' => '<!doctype html><html lang="{{ $layout->language }}"><head><title>{{ $layout->title }}</title>@verseAssets</head><body class="paper"><x-banner :text="$layout->brandName" />@yield(\'content\')</body></html>',
            'paper/views/components/banner.blade.php' => '@props([\'text\'])<h1 class="paper-banner">{{ $text }}</h1>',
        ];
        foreach (self::ROLES as $role) {
            $files['paper/views/'.$role.'.blade.php'] = "@extends('verse::layouts.base')\n@section('content')\nPAPER-".$role."\n@endsection\n";
        }
        // the whole rendering context is available to every view
        $files['paper/views/article.blade.php'] = "@extends('verse::layouts.base')\n@section('content')\nPAPER-article {{ \$article->title }} flavor={{ \$site->extra['flavor'] }} mood={{ \$page->meta->extra['mood'] ?? 'none' }} articles={{ count(\$articles->items) }} toc={{ \$theme->toc ? 'on' : 'off' }} menu={{ count(\$menu->items) }} tagline={{ \$site->tagline }} role={{ \$page->role->value }}\n@endsection\n";
        $files['paper/views/home.blade.php'] = "@extends('verse::layouts.base')\n@section('content')\nPAPER-home page={{ \$page === null ? 'none' : 'some' }} articles={{ count(\$articles->items) }}\n@endsection\n";
        $files['paper/views/listing.blade.php'] = "@extends('verse::layouts.base')\n@section('content')\nPAPER-listing page={{ \$page === null ? 'none' : 'some' }} articles={{ count(\$articles->items) }}\n@endsection\n";
        $files['paper/views/page.blade.php'] = "@extends('verse::layouts.base')\n@section('content')\nPAPER-page {{ \$page->meta->title }} {{ \$page->role->value }} {{ \$page->meta->extra['mood'] ?? 'none' }} {{ \$articles === null ? 'no-articles' : 'articles' }}\n@endsection\n";

        return $files;
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    public static function paperWith(array $overrides): array
    {
        return array_replace(self::paper(), $overrides);
    }

    private function site(): string
    {
        $trace = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sites/notes/content/articles/trace/page.md');

        return $this->fixtureSite('notes', [
            'verse.yaml' => (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sites/notes/verse.yaml')."theme:\n  name: paper\nextra:\n  flavor: sweet\n",
            'content/articles/trace/page.md' => str_replace('permalink:', "extra:\n  mood: calm\npermalink:", $trace),
            'content/about.md' => "---\ntitle: About\nmenu: true\nextra:\n  mood: warm\n---\nAbout.\n",
        ]);
    }

    #[Test]
    public function a_second_theme_renders_with_its_own_views_and_assets(): void
    {
        $themes = $this->themeRegistry(self::paper());
        $root = $this->site();

        $this->buildSite($root, themes: $themes);

        $home = (string) file_get_contents($root.'/dist/index.html');
        $this->assertStringContainsString('class="paper"', $home);
        $this->assertStringContainsString('<h1 class="paper-banner">Notes</h1>', $home);
        $this->assertStringContainsString('PAPER-home', $home);
        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/theme/main-abc.css">', $home);
        $this->assertStringContainsString('<script type="module" src="/assets/theme/main-def.js"></script>', $home);
        $this->assertStringContainsString('PAPER-home page=none articles=3', $home);
        $this->assertStringContainsString(
            'PAPER-article The bug hiding in a successful trace flavor=sweet mood=calm articles=3 toc=on menu=5 tagline=A fixture site for the Verse test suite role=article',
            (string) file_get_contents($root.'/dist/essays/trace/index.html'),
        );
        $this->assertStringContainsString('PAPER-page About page warm no-articles', (string) file_get_contents($root.'/dist/about/index.html'));
        $this->assertStringContainsString('PAPER-listing page=none articles=3', (string) file_get_contents($root.'/dist/tags/laravel/index.html'));
        $this->assertStringContainsString('PAPER-tags', (string) file_get_contents($root.'/dist/tags/index.html'));
        $this->assertStringContainsString('PAPER-listing page=none articles=3', (string) file_get_contents($root.'/dist/articles/index.html'));
        $this->assertStringContainsString('PAPER-not-found', (string) file_get_contents($root.'/dist/404.html'));

        $assets = array_keys(self::snapshot($root.'/dist/assets/theme'));
        $this->assertSame(['main-abc.css', 'main-def.js', 'paper-font.woff2'], $assets);
    }

    #[Test]
    public function the_bundled_theme_is_the_default(): void
    {
        $root = $this->fixtureSite();
        $this->buildSite($root);

        $this->assertStringContainsString('class="site-header"', (string) file_get_contents($root.'/dist/index.html'));
        $this->assertDirectoryExists($root.'/dist/assets/theme');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredViews(): array
    {
        return array_map(
            static fn (string $name): array => [$name],
            array_combine(
                ['layouts/base', ...self::ROLES],
                ['layouts/base', ...self::ROLES],
            ),
        );
    }

    #[DataProvider('requiredViews')]
    #[Test]
    public function a_theme_missing_a_required_view_is_a_theme_error_naming_it(string $view): void
    {
        $files = self::paper();
        unset($files['paper/views/'.$view.'.blade.php']);
        $root = $this->site();

        try {
            $this->buildSite($root, themes: $this->themeRegistry($files));
            $this->fail('Expected a ThemeException');
        } catch (ThemeException $exception) {
            $this->assertStringContainsString('"'.$view.'"', $exception->getMessage());
            $this->assertStringContainsString('themes/paper', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($root.'/dist');
    }

    #[Test]
    public function a_missing_theme_asset_fails_the_build_before_anything_is_published(): void
    {
        $files = self::paper();
        unset($files['paper/dist/paper-font.woff2']);
        $root = $this->site();

        try {
            $this->buildSite($root, themes: $this->themeRegistry($files));
            $this->fail('Expected a ThemeException');
        } catch (ThemeException $exception) {
            $this->assertStringContainsString('paper-font.woff2', $exception->getMessage());
            $this->assertStringContainsString('themes/paper/dist/.vite/manifest.json', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($root.'/dist');
    }

    #[Test]
    public function an_unknown_theme_name_is_a_config_error_listing_the_available_themes(): void
    {
        $root = $this->site();

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Available themes: default');
        $this->buildSite($root, themes: ThemeRegistry::bundled());
    }
}
