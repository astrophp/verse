<?php

declare(strict_types=1);

namespace Astro\Verse\Theme;

use Astro\Verse\Support\Fields;

/**
 * Turns the raw `theme` map of `verse.yaml` into resolved settings: picks the theme by name, rejects keys
 * the theme does not declare, validates the values with the typed rules and merges them over the theme's defaults.
 */
final class ThemeSettingsResolver
{
    public const string DEFAULT_THEME = 'default';

    public function __construct(private readonly ThemeRegistry $themes) {}

    /**
     * @param  Fields  $theme  the `theme` map (empty when absent); errors are config errors on `theme.*`
     * @param  string  $siteRoot  site root, for the `theme.logo` file check
     */
    public function resolve(Fields $theme, string $siteRoot): ThemeSettings
    {
        $name = $theme->nonEmptyString('name') ?? self::DEFAULT_THEME;
        if (preg_match(ThemeRegistry::NAME_PATTERN, $name) !== 1) {
            $theme->fail('"'.$name.'" is not a valid theme name (lowercase letters, digits and "-", e.g. default).', 'name');
        }

        $available = $this->themes->names();
        if (! in_array($name, $available, true)) {
            $theme->fail('unknown theme "'.$name.'". Available themes: '.($available === [] ? '(none)' : implode(', ', $available)).'.', 'name');
        }

        $definition = $this->themes->find($name);

        foreach (array_keys($theme->all()) as $key) {
            $key = (string) $key;
            if ($key !== 'name' && ! in_array($key, $definition->declaredSettings, true)) {
                $theme->fail(
                    'unknown key "'.$theme->fieldPath($key).'": theme "'.$name.'" '
                    .($definition->declaredSettings === []
                        ? 'has no settings'
                        : 'has the settings '.implode(', ', $definition->declaredSettings)).'.',
                    $key,
                );
            }
        }

        return SettingRules::apply($definition->defaults, $theme, $siteRoot);
    }
}
