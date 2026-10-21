<?php

declare(strict_types=1);

namespace Astro\Verse\Theme;

use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Error\ThemeException;
use Astro\Verse\Support\Fields;
use Astro\Verse\Support\YamlMap;
use Closure;

/**
 * Reads and validates `themes/<name>/theme.yaml`.
 */
final class ThemeLoader
{
    public const string FILE = 'theme.yaml';

    public function load(string $root, string $name): Theme
    {
        $file = 'themes/'.$name.'/'.self::FILE;
        $text = file_get_contents($root.'/'.self::FILE);
        if ($text === false) {
            throw new FilesystemException('cannot read the file.', $file);
        }

        /** @var Closure(string, ?string): ThemeException $error */
        $error = static fn (string $detail, ?string $field): ThemeException => new ThemeException($detail, $file, $field);
        $fields = new Fields(YamlMap::parse($text, $error), $error);

        $fields->rejectUnknown(['name', 'description', 'entries', 'settings']);

        $declared = $fields->requiredString('name');
        if ($declared !== $name) {
            $fields->fail('must equal the directory name "'.$name.'", got "'.$declared.'".', 'name');
        }
        $fields->string('description');

        $settings = $fields->nested('settings');
        $declaredSettings = [];
        $defaults = new ThemeSettings($name);
        if ($settings !== null) {
            $settings->rejectUnknown(SettingRules::KNOWN, '; Verse knows the settings '.implode(', ', SettingRules::KNOWN));
            $declaredSettings = array_values(array_filter(
                SettingRules::KNOWN,
                static fn (string $setting): bool => $settings->has($setting),
            ));
            $defaults = SettingRules::apply($defaults, $settings, null);
        }

        return new Theme(
            name: $name,
            root: $root,
            viewsPath: $root.'/views',
            distPath: $root.'/dist',
            manifestPath: $root.'/dist/.vite/manifest.json',
            entries: $this->entries($fields),
            declaredSettings: $declaredSettings,
            defaults: $defaults,
        );
    }

    /**
     * @return list<string>
     */
    private function entries(Fields $fields): array
    {
        $entries = $fields->list('entries');
        if ($entries === null || $entries === []) {
            $fields->fail('is required: list the Vite manifest keys the theme loads, e.g. [src/app.css, src/app.js].', 'entries');
        }

        $keys = [];
        foreach ($entries as $index => $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                $fields->fail('expected a non-empty manifest key, got '.Fields::describe($entry).'.', 'entries.'.$index);
            }
            if (in_array($entry, $keys, true)) {
                $fields->fail('lists "'.$entry.'" twice.', 'entries.'.$index);
            }
            $keys[] = $entry;
        }

        return $keys;
    }
}
