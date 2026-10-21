<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Theme;

use Astro\Verse\Error\ErrorKind;
use Astro\Verse\Error\ThemeException;
use Astro\Verse\Tests\Support\TestCase;
use Astro\Verse\Theme\ThemeRegistry;
use Astro\Verse\Verse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class ThemeRegistryTest extends TestCase
{
    private const string PAPER = "name: paper\ndescription: A quiet theme.\nentries: [src/main.css, src/main.js]\nsettings: {toc: false, accent: '#336699'}\n";

    /**
     * @param  callable(): mixed  $callback
     */
    private function assertThemeError(callable $callback, ?string $file, ?string $field, string $messagePart = ''): void
    {
        try {
            $callback();
        } catch (ThemeException $exception) {
            $this->assertSame(ErrorKind::Theme, $exception->kind());
            $this->assertSame($file, $exception->sourceFile, $exception->getMessage());
            $this->assertSame($field, $exception->field, $exception->getMessage());
            $this->assertStringContainsString($messagePart, $exception->getMessage());

            return;
        }
        $this->fail('Expected a ThemeException for field '.$field);
    }

    #[Test]
    public function it_finds_the_bundled_default_theme(): void
    {
        $registry = ThemeRegistry::bundled();
        $theme = $registry->find('default');

        $this->assertSame(['default'], $registry->names());
        $this->assertSame('default', $theme->name);
        $this->assertSame(Verse::themesPath().'/default', $theme->root);
        $this->assertSame($theme->root.'/views', $theme->viewsPath);
        $this->assertSame($theme->root.'/dist', $theme->distPath);
        $this->assertSame($theme->root.'/dist/.vite/manifest.json', $theme->manifestPath);
        $this->assertSame(['src/app.css', 'src/app.js'], $theme->entries);
        $this->assertSame(['logo', 'accent', 'toc'], $theme->declaredSettings);
        $this->assertSame('default', $theme->defaults->name);
        $this->assertNull($theme->defaults->logo);
        $this->assertNull($theme->defaults->accentLight);
        $this->assertNull($theme->defaults->accentDark);
        $this->assertTrue($theme->defaults->toc);
        $this->assertSame($theme, $registry->find('default'));
    }

    #[Test]
    public function the_bundled_default_theme_ships_a_complete_build(): void
    {
        $theme = ThemeRegistry::bundled()->find('default');

        $this->assertFileExists($theme->manifestPath);
        $manifest = json_decode((string) file_get_contents($theme->manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);
        foreach ($theme->entries as $entry) {
            $this->assertArrayHasKey($entry, $manifest);
        }
    }

    #[Test]
    public function it_lists_and_finds_themes_from_a_fixture_root(): void
    {
        $registry = $this->themeRegistry([
            'zeta/theme.yaml' => "name: zeta\nentries: [a.css]\n",
            'paper/theme.yaml' => self::PAPER,
            'no-manifest/readme.txt' => 'not a theme',
            'Bad_Name/theme.yaml' => "name: Bad_Name\nentries: [a.css]\n",
        ]);

        $this->assertSame(['paper', 'zeta'], $registry->names());

        $paper = $registry->find('paper');
        $this->assertSame('paper', $paper->name);
        $this->assertSame(['src/main.css', 'src/main.js'], $paper->entries);
        $this->assertSame(['accent', 'toc'], $paper->declaredSettings);
        $this->assertFalse($paper->defaults->toc);
        $this->assertSame('#336699', $paper->defaults->accentLight);
        $this->assertSame('#336699', $paper->defaults->accentDark);

        $zeta = $registry->find('zeta');
        $this->assertSame([], $zeta->declaredSettings);
        $this->assertTrue($zeta->defaults->toc);
    }

    #[Test]
    public function an_all_numeric_theme_name_works(): void
    {
        $registry = $this->themeRegistry([
            '123/theme.yaml' => "name: '123'\nentries: [a.css]\n",
            'paper/theme.yaml' => self::PAPER,
        ]);

        $this->assertSame(['123', 'paper'], $registry->names());
        $this->assertSame('123', $registry->find('123')->name);
    }

    #[Test]
    public function an_earlier_root_wins_and_names_are_merged(): void
    {
        $first = $this->makeSite(['paper/theme.yaml' => self::PAPER]);
        $second = $this->makeSite([
            'paper/theme.yaml' => "name: paper\nentries: [other.css]\n",
            'ink/theme.yaml' => "name: ink\nentries: [ink.css]\n",
        ]);
        $registry = new ThemeRegistry([$first, $second]);

        $this->assertSame(['ink', 'paper'], $registry->names());
        $this->assertSame(['src/main.css', 'src/main.js'], $registry->find('paper')->entries);
        $this->assertSame(['ink.css'], $registry->find('ink')->entries);
    }

    #[Test]
    #[DataProvider('unknownNames')]
    public function an_unknown_theme_is_a_theme_error_listing_the_available_names(string $name): void
    {
        $registry = $this->themeRegistry(['paper/theme.yaml' => self::PAPER]);

        $this->assertThemeError(fn () => $registry->find($name), null, null, 'Available themes: paper.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownNames(): iterable
    {
        yield 'missing' => ['nope'];
        yield 'path traversal' => ['../paper'];
        yield 'uppercase' => ['Paper'];
        yield 'empty' => [''];
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function invalidThemeFiles(): iterable
    {
        yield 'invalid yaml' => ["name: [paper\n", null, 'invalid YAML'];
        yield 'not a mapping' => ["- a\n- b\n", null, 'expected a YAML mapping'];
        yield 'unknown key' => ["name: paper\nentries: [a.css]\nviews: x\n", 'views', 'unknown key "views"'];
        yield 'missing name' => ["entries: [a.css]\n", 'name', 'is required'];
        yield 'name differs from directory' => ["name: other\nentries: [a.css]\n", 'name', 'must equal the directory name "paper"'];
        yield 'name not a string' => ["name: 5\nentries: [a.css]\n", 'name', 'expected a string'];
        yield 'description not a string' => ["name: paper\ndescription: [a]\nentries: [a.css]\n", 'description', 'expected a string'];
        yield 'missing entries' => ["name: paper\n", 'entries', 'is required'];
        yield 'empty entries' => ["name: paper\nentries: []\n", 'entries', 'is required'];
        yield 'entries not a list' => ["name: paper\nentries: {a: b}\n", 'entries', 'expected a list'];
        yield 'entry not a string' => ["name: paper\nentries: [5]\n", 'entries.0', 'non-empty manifest key'];
        yield 'blank entry' => ["name: paper\nentries: ['a.css', ' ']\n", 'entries.1', 'non-empty manifest key'];
        yield 'duplicate entry' => ["name: paper\nentries: [a.css, a.css]\n", 'entries.1', 'twice'];
        yield 'settings not a mapping' => ["name: paper\nentries: [a.css]\nsettings: [toc]\n", 'settings', 'expected a mapping'];
        yield 'unknown setting' => ["name: paper\nentries: [a.css]\nsettings: {sidebar: true}\n", 'settings.sidebar', 'unknown key "settings.sidebar"'];
        yield 'invalid toc default' => ["name: paper\nentries: [a.css]\nsettings: {toc: maybe}\n", 'settings.toc', 'expected true or false'];
        yield 'invalid accent default' => ["name: paper\nentries: [a.css]\nsettings: {accent: red}\n", 'settings.accent', 'hex colour'];
        yield 'invalid accent mode default' => ["name: paper\nentries: [a.css]\nsettings: {accent: {dark: 'red;}'}}\n", 'settings.accent.dark', 'hex colour'];
        yield 'invalid logo default' => ["name: paper\nentries: [a.css]\nsettings: {logo: icon.svg}\n", 'settings.logo', 'site-root path'];
    }

    #[Test]
    #[DataProvider('invalidThemeFiles')]
    public function an_invalid_theme_yaml_names_the_file_and_the_key(string $yaml, ?string $field, string $messagePart): void
    {
        $registry = $this->themeRegistry(['paper/theme.yaml' => $yaml]);

        $this->assertThemeError(fn () => $registry->find('paper'), 'themes/paper/theme.yaml', $field, $messagePart);
    }

    #[Test]
    public function the_error_message_starts_with_the_theme_file(): void
    {
        $registry = $this->themeRegistry(['paper/theme.yaml' => "name: other\nentries: [a.css]\n"]);

        try {
            $registry->find('paper');
            $this->fail('Expected a ThemeException');
        } catch (ThemeException $exception) {
            $this->assertStringStartsWith('theme error in themes/paper/theme.yaml, field "name": ', $exception->getMessage());
        }
    }

    #[Test]
    public function a_logo_default_may_be_an_absolute_url(): void
    {
        $registry = $this->themeRegistry(['paper/theme.yaml' => "name: paper\nentries: [a.css]\nsettings: {logo: 'https://example.test/logo.svg'}\n"]);

        $this->assertSame('https://example.test/logo.svg', $registry->find('paper')->defaults->logo);
    }
}
