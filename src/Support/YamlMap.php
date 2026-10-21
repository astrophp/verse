<?php

declare(strict_types=1);

namespace Astro\Verse\Support;

use Astro\Verse\Error\VerseException;
use Closure;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * YAML as data only: no objects, no custom tags. The document must be a mapping (or empty).
 */
final class YamlMap
{
    /**
     * @param  Closure(string, ?string): VerseException  $error  builds the exception for (detail, field)
     * @return array<array-key, mixed>
     */
    public static function parse(string $yaml, Closure $error): array
    {
        try {
            $data = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            throw $error('invalid YAML: '.$exception->getMessage(), null);
        }

        if ($data === null) {
            return [];
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw $error('expected a YAML mapping of keys to values', null);
        }

        return $data;
    }
}
