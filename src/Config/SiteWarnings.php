<?php

declare(strict_types=1);

namespace Astro\Verse\Config;

final class SiteWarnings
{
    /**
     * Scaffold placeholders that must be replaced before publishing.
     *
     * @return list<string>
     */
    public static function placeholders(SiteConfig $config): array
    {
        $warnings = [];

        if ($config->url !== null) {
            $host = $config->url->host();
            foreach (['example.com', 'example.org'] as $placeholder) {
                if ($host === $placeholder || str_ends_with($host, '.'.$placeholder)) {
                    $warnings[] = 'verse.yaml: url uses the placeholder host "'.$host.'"; set your real production URL.';
                }
            }
        }

        if ($config->author !== null && $config->author->name === 'Your Name') {
            $warnings[] = 'verse.yaml: author.name is still the placeholder "Your Name".';
        }

        foreach ($config->socialLinks as $link) {
            if (str_contains($link->url, 'your-username')) {
                $warnings[] = 'verse.yaml: social_links.'.$link->key.' still contains the placeholder "your-username".';
            }
        }

        return $warnings;
    }

    /**
     * Site-level `theme/` and `themes/` directories are not supported in this version.
     *
     * @return list<string>
     */
    public static function themeDirectory(string $root): array
    {
        $warnings = [];
        foreach (['theme', 'themes'] as $directory) {
            if (is_dir(rtrim($root, '/').'/'.$directory)) {
                $warnings[] = 'site themes are not supported in this version; the '.$directory.'/ directory is ignored';
            }
        }

        return $warnings;
    }
}
