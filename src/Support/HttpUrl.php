<?php

declare(strict_types=1);

namespace Astro\Verse\Support;

final class HttpUrl
{
    /**
     * An absolute http(s) URL with a valid authority and no whitespace or control characters.
     */
    public static function isValid(string $value): bool
    {
        if (preg_match('~^https?://([^/?#]*)([^\s\x00-\x1f\x7f]*)$~iu', $value, $m) !== 1) {
            return false;
        }

        return self::isValidAuthority($m[1]);
    }

    /**
     * `host[:port]`: no userinfo; the host is a hostname (labels of letters, digits and inner hyphens, Unicode
     * letters allowed), `localhost` or an IPv4 literal; the port is 1-65535. IPv6 literals are not supported.
     */
    public static function isValidAuthority(string $authority): bool
    {
        $label = '[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?';
        if (preg_match('/^('.$label.'(?:\.'.$label.')*)(?::(\d{1,5}))?$/u', $authority, $m) !== 1) {
            return false;
        }

        return ! isset($m[2]) || ((int) $m[2] >= 1 && (int) $m[2] <= 65535);
    }
}
