<?php

declare(strict_types=1);

namespace Astro\Verse\Support;

final class Slug
{
    /**
     * The one slug function: used for heading IDs and tag slugs.
     * NFC, lowercase, runs of characters that are not letters, combining marks or digits become `-`, trimmed, empty becomes `section`.
     */
    public static function make(string $text): string
    {
        $slug = mb_strtolower(Unicode::nfc($text));
        $slug = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug === '' ? 'section' : $slug;
    }
}
