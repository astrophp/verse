<?php

declare(strict_types=1);

namespace Astro\Verse\Theme;

use Astro\Verse\Support\Fields;
use Astro\Verse\Support\HttpUrl;

/**
 * The typed theme settings Verse knows and their validators. The same rules check a theme's
 * defaults in `theme.yaml` and a site's overrides in `verse.yaml`; errors come from the `Fields`
 * they are given, so each file reports its own exception type.
 */
final class SettingRules
{
    /** @var list<string> */
    public const array KNOWN = ['logo', 'accent', 'toc'];

    /**
     * Applies the settings present in `$values` on top of `$base`. An absent key keeps the base value; a
     * present key overrides it, an explicit `null` included (`logo`, `accent` and each accent mode are
     * nullable); `toc: false` is a real override. Keys other than the known settings are ignored here
     * (callers reject unknown keys first).
     *
     * @param  ?string  $siteRoot  site root for the `public/` file check; null skips it (theme defaults)
     */
    public static function apply(ThemeSettings $base, Fields $values, ?string $siteRoot): ThemeSettings
    {
        $logo = $base->logo;
        if ($values->has('logo')) {
            $logo = $values->raw('logo') === null ? null : self::logo($values, $siteRoot);
        }

        $light = $base->accentLight;
        $dark = $base->accentDark;
        if ($values->has('accent')) {
            [$light, $dark] = self::accent($values, $light, $dark);
        }

        return new ThemeSettings(
            $base->name,
            $logo,
            $light,
            $dark,
            $values->has('toc') ? $values->bool('toc', $base->toc) : $base->toc,
        );
    }

    private static function logo(Fields $fields, ?string $siteRoot): string
    {
        $logo = $fields->nonEmptyString('logo') ?? '';

        if (HttpUrl::isValid($logo)) {
            return $logo;
        }

        $valid = str_starts_with($logo, '/')
            && ! str_starts_with($logo, '//')
            && preg_match('/[\x00-\x1f\x7f\\\\%?#]/', $logo) !== 1
            && ! in_array('..', explode('/', $logo), true)
            && ! in_array('.', explode('/', $logo), true);
        if (! $valid) {
            $fields->fail('expected a site-root path to a public/ file (e.g. /icon.svg) or an absolute URL, got "'.$logo.'".', 'logo');
        }
        if ($siteRoot !== null && ! is_file(rtrim($siteRoot, '/').'/public'.$logo)) {
            $fields->fail('"'.$logo.'" was not found: add the file as public'.$logo.'.', 'logo');
        }

        return $logo;
    }

    /**
     * The accent colours after applying the `accent` key: `null` clears both, a string sets both, a map sets
     * the modes it names (an explicit `null` clears that mode) and keeps the others.
     *
     * @return array{0: ?string, 1: ?string} light, dark
     */
    private static function accent(Fields $fields, ?string $light, ?string $dark): array
    {
        $value = $fields->raw('accent');
        if ($value === null) {
            return [null, null];
        }
        if (is_string($value)) {
            $color = self::color($fields, 'accent', $value);

            return [$color, $color];
        }

        $accent = $fields->nested('accent');
        if ($accent === null) {
            $fields->fail('expected a hex colour or a mapping with light and dark, got '.Fields::describe($value).'.', 'accent');
        }
        $accent->rejectUnknown(['light', 'dark']);

        return [
            $accent->has('light') ? self::optionalColor($accent, 'light') : $light,
            $accent->has('dark') ? self::optionalColor($accent, 'dark') : $dark,
        ];
    }

    private static function optionalColor(Fields $accent, string $key): ?string
    {
        $value = $accent->raw($key);
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            $accent->fail('expected a hex colour, got '.Fields::describe($value).'.', $key);
        }

        return self::color($accent, $key, $value);
    }

    private static function color(Fields $fields, string $key, string $value): string
    {
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) !== 1) {
            $fields->fail('expected a hex colour (#rgb, #rrggbb or #rrggbbaa), got "'.$value.'".', $key);
        }

        return $value;
    }
}
