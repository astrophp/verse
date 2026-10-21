<?php

declare(strict_types=1);

namespace Astro\Verse\Content;

use Astro\Verse\Error\ContentException;
use Astro\Verse\Support\Fields;
use Astro\Verse\Support\HttpUrl;
use Astro\Verse\Support\Unicode;
use Astro\Verse\Support\YamlMap;
use DateTimeImmutable;
use DateTimeZone;

final class FrontMatterParser
{
    /** Supported date representations; anything else is rejected. */
    private const string DATE_VALUE = '\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?)?';

    private const string DATE_HINT = 'write it as `%s: YYYY-MM-DD` or an ISO 8601 timestamp (YYYY-MM-DDTHH:MM[:SS] with optional Z or ±HH:MM) on its own line.';

    /**
     * @param  string  $path  site-relative path used in messages
     * @param  DateTimeZone  $timezone  interprets dates and timestamps without an offset
     */
    public function parse(string $source, string $path, DateTimeZone $timezone): ParsedDocument
    {
        [$yaml, $body] = $this->split($source, $path);

        $error = static fn (string $detail, ?string $field): ContentException => new ContentException($detail, $path, $field);
        $fields = new Fields(YamlMap::parse($yaml, $error), $error);

        $fields->rejectUnknown(
            ['title', 'description', 'date', 'updated', 'draft', 'tags', 'slug', 'permalink', 'canonical', 'image', 'external_url', 'menu', 'order', 'extra'],
            ' (put custom values under "extra")',
        );

        $title = $fields->requiredString('title');
        $permalink = $this->permalink($fields);
        $externalUrl = $this->url($fields, 'external_url');

        if ($externalUrl !== null && $permalink !== null) {
            $fields->fail('cannot be combined with "external_url": external items have no local page. Remove one of them.', 'permalink');
        }

        $frontMatter = new FrontMatter(
            title: $title,
            description: $fields->string('description'),
            date: $this->date($fields, 'date', $yaml, $timezone),
            updated: $this->date($fields, 'updated', $yaml, $timezone),
            draft: $fields->bool('draft', false),
            tags: $this->tags($fields),
            slug: $this->slug($fields),
            permalink: $permalink,
            canonical: $this->url($fields, 'canonical'),
            image: $this->image($fields),
            externalUrl: $externalUrl,
            menu: $fields->bool('menu', false),
            order: $fields->int('order'),
            extra: $fields->map('extra') ?? [],
        );

        return new ParsedDocument($frontMatter, $body);
    }

    /**
     * @return array{0: string, 1: string} the front matter text and the Markdown body
     */
    private function split(string $source, string $path): array
    {
        if (str_starts_with($source, "\u{FEFF}")) {
            $source = substr($source, 3);
        }
        $source = str_replace("\r\n", "\n", $source);

        $lines = explode("\n", $source);
        if ($lines[0] !== '---') {
            throw new ContentException(
                'the file must start with front matter: a "---" line, "title: …" and a closing "---" line (the title is required).',
                $path,
            );
        }

        for ($i = 1, $count = count($lines); $i < $count; $i++) {
            if (rtrim($lines[$i]) === '---') {
                return [
                    implode("\n", array_slice($lines, 1, $i - 1)),
                    implode("\n", array_slice($lines, $i + 1)),
                ];
            }
        }

        throw new ContentException('the front matter is not closed. Add a closing "---" line after the metadata.', $path);
    }

    /**
     * `date`/`updated` are read from the front matter text because the YAML parser turns every timestamp into UTC
     * and cannot keep the original representation.
     */
    private function date(Fields $fields, string $key, string $yaml, DateTimeZone $timezone): ?DateTimeImmutable
    {
        if (! $fields->has($key)) {
            return null;
        }

        $pattern = '/^'.$key.':[ \t]+(?|\'('.self::DATE_VALUE.')\'|"('.self::DATE_VALUE.')"|('.self::DATE_VALUE.'))(?:[ \t]+#.*|[ \t]*)$/m';
        $count = preg_match_all($pattern, $yaml, $matches, PREG_OFFSET_CAPTURE);
        if ($count !== 1) {
            $got = Fields::describe($fields->raw($key));
            $fields->fail(
                ($count === 0 ? 'unsupported date format (got '.$got.'): ' : 'is defined more than once: ')
                .sprintf(self::DATE_HINT, $key),
                $key,
            );
        }

        $value = $matches[1][0][0];
        $this->assertLineIsTheKey($fields, $key, $yaml, $matches[0][0][0], $matches[0][0][1], $value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2}))?(Z|[+-]\d{2}:\d{2})?)?$/', $value, $m) !== 1) {
            $fields->fail(sprintf(self::DATE_HINT, $key), $key);
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        $hour = (int) ($m[4] ?? 0);
        $minute = (int) ($m[5] ?? 0);
        $second = (int) ($m[6] ?? 0);
        $zone = $m[7] ?? '';

        $valid = checkdate($month, $day, $year)
            && $hour <= 23 && $minute <= 59 && $second <= 59
            && ($zone === '' || $zone === 'Z' || ((int) substr($zone, 1, 2) <= 23 && (int) substr($zone, 4, 2) <= 59));
        if (! $valid) {
            $fields->fail('"'.$value.'" is not a valid calendar date or time. Check the day, month, hour and minute ranges.', $key);
        }

        $zoneObject = match (true) {
            $zone === '' => $timezone,
            $zone === 'Z' => new DateTimeZone('UTC'),
            default => new DateTimeZone($zone),
        };

        return new DateTimeImmutable(
            sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
            $zoneObject,
        );
    }

    /**
     * The matched text line must really be the top-level `$key` entry, not text inside another scalar or a
     * line with a continuation: the parsed value has to agree with the text, and replacing the line by a probe
     * value must change exactly that key.
     */
    private function assertLineIsTheKey(Fields $fields, string $key, string $yaml, string $line, int $offset, string $value): void
    {
        $unsupported = fn (): never => $fields->fail(
            'unsupported date format (got '.Fields::describe($fields->raw($key)).'): '.sprintf(self::DATE_HINT, $key),
            $key,
        );

        $raw = $fields->raw($key);
        if (! is_int($raw) && $raw !== $value) {
            $unsupported();
        }

        $probe = '__verse_date_probe__';
        $probed = substr($yaml, 0, $offset).$key.': "'.$probe.'"'.substr($yaml, $offset + strlen($line));
        try {
            $parsed = YamlMap::parse($probed, static fn (string $detail, ?string $field): ContentException => new ContentException($detail));
        } catch (ContentException) {
            $unsupported();
        }
        if (($parsed[$key] ?? null) !== $probe) {
            $unsupported();
        }
    }

    /**
     * @return list<string>
     */
    private function tags(Fields $fields): array
    {
        $list = $fields->list('tags');
        if ($list === null) {
            return [];
        }

        $tags = [];
        foreach ($list as $tag) {
            if (! is_string($tag) || trim($tag) === '') {
                $fields->fail('expected a list of non-empty strings, got '.Fields::describe($tag).'.', 'tags');
            }
            $label = Unicode::nfc(trim($tag));
            if (in_array($label, $tags, true)) {
                $fields->fail('contains "'.$label.'" more than once. Remove the duplicate.', 'tags');
            }
            $tags[] = $label;
        }

        return $tags;
    }

    private function slug(Fields $fields): ?string
    {
        $slug = $fields->nonEmptyString('slug');
        if ($slug === null) {
            return null;
        }

        $violation = NameRules::violation($slug);
        if ($violation !== null) {
            $fields->fail('"'.$slug.'" cannot be used as a URL segment because '.$violation.'.', 'slug');
        }

        return Unicode::nfc($slug);
    }

    private function permalink(Fields $fields): ?string
    {
        $permalink = $fields->nonEmptyString('permalink');
        if ($permalink === null) {
            return null;
        }

        $hint = ' A permalink is a site-relative directory route such as /notes/my-page/.';
        if (preg_match('~^/(?:[^/]+/)+$~', $permalink) !== 1) {
            $fields->fail('"'.$permalink.'" must start and end with "/" and contain no empty segments.'.$hint, 'permalink');
        }

        $segments = explode('/', trim($permalink, '/'));
        foreach ($segments as $segment) {
            $violation = NameRules::violation($segment);
            if ($violation !== null) {
                $fields->fail('the segment "'.$segment.'" is not allowed because '.$violation.'.'.$hint, 'permalink');
            }
        }

        $permalink = Unicode::nfc($permalink);
        $lower = mb_strtolower($permalink);
        if (str_starts_with($lower, '/assets/theme/') || str_starts_with($lower, '/__verse/')) {
            $fields->fail('"'.$permalink.'" is reserved for theme assets and preview internals. Choose another route.', 'permalink');
        }

        return $permalink;
    }

    private function url(Fields $fields, string $key): ?string
    {
        $url = $fields->nonEmptyString($key);
        if ($url !== null && ! HttpUrl::isValid($url)) {
            $fields->fail('expected an absolute http(s) URL, got "'.$url.'".', $key);
        }

        return $url;
    }

    private function image(Fields $fields): ?string
    {
        $image = $fields->nonEmptyString('image');
        if ($image === null) {
            return null;
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $image) === 1) {
            if (! HttpUrl::isValid($image)) {
                $fields->fail('expected an absolute http(s) URL, a /site-root path or a path inside the page bundle, got "'.$image.'".', 'image');
            }
        } elseif (str_starts_with($image, '//')) {
            $fields->fail('protocol-relative URLs are not supported; use an absolute http(s) URL, got "'.$image.'".', 'image');
        }

        return $image;
    }
}
