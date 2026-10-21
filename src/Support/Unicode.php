<?php

declare(strict_types=1);

namespace Astro\Verse\Support;

use Normalizer;

final class Unicode
{
    public static function nfc(string $text): string
    {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);

        return $normalized === false ? $text : $normalized;
    }

    /**
     * Case-insensitive, deterministic key for labels and titles.
     */
    public static function sortKey(string $text): string
    {
        return mb_strtolower(self::nfc($text));
    }
}
