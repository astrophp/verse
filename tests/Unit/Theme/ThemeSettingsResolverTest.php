<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Theme;

use Astro\Verse\Config\ConfigLoader;
use Astro\Verse\Error\ConfigException;
use Astro\Verse\Tests\Support\TestCase;
use Astro\Verse\Theme\ThemeRegistry;
use Astro\Verse\Theme\ThemeSettings;
use PHPUnit\Framework\Attributes\Test;

final class ThemeSettingsResolverTest extends TestCase
{
    private const string PAPER = "name: paper\nentries: [a.css]\nsettings: {toc: false, accent: {light: '#112233', dark: '#445566'}}\n";

    private function registry(): ThemeRegistry
    {
        return $this->themeRegistry([
            'default/theme.yaml' => "name: default\nentries: [a.css]\nsettings: {logo: null, accent: null, toc: true}\n",
            'paper/theme.yaml' => self::PAPER,
            'bare/theme.yaml' => "name: bare\nentries: [a.css]\n",
        ]);
    }

    private function resolve(string $themeYaml, ?ThemeRegistry $registry = null, string $root = '/nonexistent'): ThemeSettings
    {
        $config = (new ConfigLoader($registry ?? $this->registry()))->parseSite("title: x\n".$themeYaml, $root, false);

        return $config->theme;
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function assertConfigError(callable $callback, string $field, string ...$messageParts): void
    {
        try {
            $callback();
        } catch (ConfigException $exception) {
            $this->assertSame($field, $exception->field, $exception->getMessage());
            $this->assertSame('verse.yaml', $exception->sourceFile);
            foreach ($messageParts as $part) {
                $this->assertStringContainsString($part, $exception->getMessage());
            }

            return;
        }
        $this->fail('Expected a ConfigException for field '.$field);
    }

    #[Test]
    public function an_absent_theme_map_selects_default_with_its_defaults(): void
    {
        $settings = $this->resolve('');

        $this->assertSame('default', $settings->name);
        $this->assertNull($settings->logo);
        $this->assertNull($settings->accentLight);
        $this->assertNull($settings->accentDark);
        $this->assertTrue($settings->toc);
    }

    #[Test]
    public function the_selected_theme_supplies_the_defaults(): void
    {
        $settings = $this->resolve("theme:\n  name: paper\n");

        $this->assertSame('paper', $settings->name);
        $this->assertFalse($settings->toc);
        $this->assertSame('#112233', $settings->accentLight);
        $this->assertSame('#445566', $settings->accentDark);
    }

    #[Test]
    public function overrides_replace_defaults_key_by_key_and_false_is_a_real_override(): void
    {
        $registry = $this->themeRegistry([
            'default/theme.yaml' => "name: default\nentries: [a.css]\nsettings: {toc: true, accent: {light: '#112233', dark: '#445566'}}\n",
        ]);

        $off = $this->resolve("theme:\n  toc: false\n", $registry);
        $this->assertFalse($off->toc);
        $this->assertSame('#112233', $off->accentLight);

        $paper = $this->resolve("theme:\n  name: paper\n  toc: true\n");
        $this->assertTrue($paper->toc);
        $this->assertSame('#112233', $paper->accentLight);
    }

    #[Test]
    public function an_accent_map_with_a_missing_mode_keeps_that_modes_default(): void
    {
        $settings = $this->resolve("theme:\n  name: paper\n  accent:\n    dark: '#88aaff'\n");
        $this->assertSame('#112233', $settings->accentLight);
        $this->assertSame('#88aaff', $settings->accentDark);

        $string = $this->resolve("theme:\n  name: paper\n  accent: '#abcdef'\n");
        $this->assertSame('#abcdef', $string->accentLight);
        $this->assertSame('#abcdef', $string->accentDark);
    }

    #[Test]
    public function an_explicit_null_overrides_a_non_null_default_but_an_absent_key_keeps_it(): void
    {
        $registry = $this->themeRegistry([
            'default/theme.yaml' => "name: default\nentries: [a.css]\nsettings: {logo: 'https://example.test/default.svg', accent: {light: '#112233', dark: '#445566'}, toc: true}\n",
        ]);

        // absent keys keep the defaults
        $absent = $this->resolve('', $registry);
        $this->assertSame('https://example.test/default.svg', $absent->logo);
        $this->assertSame('#112233', $absent->accentLight);
        $this->assertSame('#445566', $absent->accentDark);

        // explicit values replace them
        $own = $this->resolve("theme:\n  logo: https://example.test/own.svg\n", $registry);
        $this->assertSame('https://example.test/own.svg', $own->logo);

        // explicit null clears them
        $cleared = $this->resolve("theme:\n  logo: ~\n  accent: ~\n", $registry);
        $this->assertNull($cleared->logo);
        $this->assertNull($cleared->accentLight);
        $this->assertNull($cleared->accentDark);
        $this->assertTrue($cleared->toc);
    }

    #[Test]
    public function an_explicit_null_accent_mode_clears_only_that_mode(): void
    {
        $registry = $this->themeRegistry([
            'default/theme.yaml' => "name: default\nentries: [a.css]\nsettings: {accent: {light: '#112233', dark: '#445566'}}\n",
        ]);

        $light = $this->resolve("theme:\n  accent:\n    light: ~\n", $registry);
        $this->assertNull($light->accentLight);
        $this->assertSame('#445566', $light->accentDark);

        $dark = $this->resolve("theme:\n  accent:\n    dark: ~\n", $registry);
        $this->assertSame('#112233', $dark->accentLight);
        $this->assertNull($dark->accentDark);

        $both = $this->resolve("theme:\n  accent:\n    light: ~\n    dark: ~\n", $registry);
        $this->assertNull($both->accentLight);
        $this->assertNull($both->accentDark);

        $empty = $this->resolve("theme:\n  accent: {}\n", $registry);
        $this->assertSame('#112233', $empty->accentLight);
        $this->assertSame('#445566', $empty->accentDark);
    }

    #[Test]
    public function the_logo_file_check_uses_the_site_root(): void
    {
        $root = $this->makeSite(['public/icon.svg' => '<svg/>']);

        $this->assertSame('/icon.svg', $this->resolve("theme:\n  logo: /icon.svg\n", root: $root)->logo);
        $this->assertConfigError(fn () => $this->resolve("theme:\n  logo: /missing.svg\n", root: $root), 'theme.logo', 'public/missing.svg');
    }

    #[Test]
    public function an_unknown_theme_name_is_a_config_error_listing_the_available_names(): void
    {
        $this->assertConfigError(
            fn () => $this->resolve("theme:\n  name: nope\n"),
            'theme.name',
            'unknown theme "nope"',
            'Available themes: bare, default, paper.',
        );
    }

    #[Test]
    public function a_registry_without_the_default_theme_rejects_an_absent_theme_map(): void
    {
        $registry = $this->themeRegistry(['paper/theme.yaml' => self::PAPER]);

        $this->assertConfigError(fn () => $this->resolve('', $registry), 'theme.name', 'unknown theme "default"', 'Available themes: paper.');
    }

    #[Test]
    public function every_other_key_must_be_a_setting_the_selected_theme_declares(): void
    {
        $this->assertConfigError(
            fn () => $this->resolve("theme:\n  colour: red\n"),
            'theme.colour',
            'unknown key "theme.colour"',
            'has the settings logo, accent, toc',
        );
        $this->assertConfigError(
            fn () => $this->resolve("theme:\n  name: paper\n  logo: /icon.svg\n"),
            'theme.logo',
            'unknown key "theme.logo"',
            'theme "paper" has the settings accent, toc',
        );
        $this->assertConfigError(
            fn () => $this->resolve("theme:\n  name: bare\n  toc: false\n"),
            'theme.toc',
            'theme "bare" has no settings',
        );
    }

    #[Test]
    public function values_are_checked_by_the_typed_validators_of_the_selected_theme(): void
    {
        $this->assertConfigError(fn () => $this->resolve("theme:\n  name: paper\n  toc: 1\n"), 'theme.toc', 'expected true or false');
        $this->assertConfigError(fn () => $this->resolve("theme:\n  name: paper\n  accent: 'red;}'\n"), 'theme.accent', 'hex colour');
    }

    #[Test]
    public function a_numeric_theme_name_can_be_selected(): void
    {
        $registry = $this->themeRegistry(['123/theme.yaml' => "name: '123'\nentries: [a.css]\nsettings: {toc: false}\n"]);

        $settings = $this->resolve("theme:\n  name: '123'\n", $registry);

        $this->assertSame('123', $settings->name);
        $this->assertFalse($settings->toc);
        $this->assertConfigError(fn () => $this->resolve("theme:\n  name: '456'\n", $registry), 'theme.name', 'Available themes: 123.');
    }

    #[Test]
    public function the_name_key_alone_is_always_accepted(): void
    {
        $this->assertSame('bare', $this->resolve("theme:\n  name: bare\n")->name);
    }
}
