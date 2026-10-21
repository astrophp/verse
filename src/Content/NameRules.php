<?php

declare(strict_types=1);

namespace Astro\Verse\Content;

final class NameRules
{
    /**
     * Why a route-forming name (folder, `.md` stem, slug, permalink segment, attachment) is unusable, or null.
     * Names must survive one decode/encode round trip, so `%` and URL delimiters are forbidden.
     */
    public static function violation(string $name, bool $allowLeadingDot = false): ?string
    {
        if ($name === '') {
            return 'it is empty';
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            return 'it contains a control character';
        }
        foreach (['/', '\\', '%', '?', '#'] as $forbidden) {
            if (str_contains($name, $forbidden)) {
                return 'it contains "'.$forbidden.'"';
            }
        }
        if (! $allowLeadingDot && str_starts_with($name, '.')) {
            return 'it starts with "."';
        }

        return null;
    }
}
