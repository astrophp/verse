<?php

declare(strict_types=1);

namespace Astro\Verse\Config;

use Astro\Verse\Error\ConfigException;
use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Support\Fields;
use Astro\Verse\Support\HttpUrl;
use Astro\Verse\Support\Unicode;
use Astro\Verse\Support\YamlMap;
use Astro\Verse\Theme\ThemeRegistry;
use Astro\Verse\Theme\ThemeSettings;
use Astro\Verse\Theme\ThemeSettingsResolver;
use Closure;
use DateTimeZone;

final class ConfigLoader
{
    public const string SITE_FILE = 'verse.yaml';

    private const array SOCIAL_LABELS = [
        'github' => 'GitHub',
        'linkedin' => 'LinkedIn',
        'medium' => 'Medium',
        'x' => 'X',
        'mastodon' => 'Mastodon',
        'bluesky' => 'Bluesky',
        'youtube' => 'YouTube',
        'email' => 'Email',
    ];

    private readonly ThemeSettingsResolver $themes;

    public function __construct(?ThemeRegistry $themes = null)
    {
        $this->themes = new ThemeSettingsResolver($themes ?? ThemeRegistry::bundled());
    }

    /**
     * @param  bool  $requireUrl  `build` needs the production URL; `serve` does not
     */
    public function load(string $root, bool $requireUrl): SiteConfig
    {
        $path = rtrim($root, '/').'/'.self::SITE_FILE;
        if (! is_file($path)) {
            throw new ConfigException(
                'verse.yaml was not found in '.$root.'. Run Verse from the site root, or create one with `verse init`.',
                self::SITE_FILE,
            );
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new FilesystemException('cannot read the file.', self::SITE_FILE);
        }

        return $this->parseSite($text, $root, $requireUrl);
    }

    public function parseSite(string $yaml, string $root, bool $requireUrl): SiteConfig
    {
        $error = self::errorFor(self::SITE_FILE);
        $fields = new Fields(YamlMap::parse($yaml, $error), $error);

        $fields->rejectUnknown(
            ['title', 'tagline', 'url', 'description', 'author', 'social_links', 'language', 'timezone', 'sitemap', 'theme', 'extra'],
            ' (put custom values under "extra")',
        );

        $title = $fields->requiredString('title');

        $url = null;
        if ($fields->has('url')) {
            $url = $this->url($fields);
        } elseif ($requireUrl) {
            $fields->fail('is required to build. Set it to the production URL, e.g. https://example.com/.', 'url');
        }

        return new SiteConfig(
            title: $title,
            tagline: $fields->nonEmptyString('tagline'),
            url: $url,
            description: $fields->string('description'),
            author: $this->author($fields),
            socialLinks: $this->socialLinks($fields),
            language: $this->language($fields),
            timezone: $this->timezone($fields),
            theme: $this->theme($fields, $root, $error),
            extra: $fields->map('extra') ?? [],
            sitemap: $fields->bool('sitemap', true),
        );
    }

    /**
     * @return Closure(string, ?string): ConfigException
     */
    private static function errorFor(string $file): Closure
    {
        return static fn (string $detail, ?string $field): ConfigException => new ConfigException($detail, $file, $field);
    }

    private function url(Fields $fields): SiteUrl
    {
        $raw = $fields->nonEmptyString('url') ?? '';
        $hint = ' Expected an http(s) origin with an optional directory base path, e.g. https://example.com/ or https://example.com/notes/.';

        if (preg_match('/[\s\x00-\x1f\x7f\\\\]/u', $raw) === 1) {
            $fields->fail('must not contain whitespace, control characters or backslashes.'.$hint, 'url');
        }
        if (preg_match('~^(https?)://([^/?#]*)([^?#]*)(.*)$~i', $raw, $m) !== 1) {
            $fields->fail('"'.$raw.'" is not an absolute http(s) URL.'.$hint, 'url');
        }
        [, $scheme, $authority, $path, $rest] = $m;

        if ($rest !== '') {
            $fields->fail('must not contain a query string or fragment.'.$hint, 'url');
        }
        if (str_contains($authority, '@')) {
            $fields->fail('must not contain user information.'.$hint, 'url');
        }
        if (! HttpUrl::isValidAuthority($authority)) {
            $fields->fail('has an invalid host "'.$authority.'".'.$hint, 'url');
        }
        if (str_contains($path, '%')) {
            $fields->fail('the path must not contain percent-encoding; write the characters directly.'.$hint, 'url');
        }

        $directory = str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
        $basePath = '/';
        if ($directory !== '') {
            $segments = explode('/', substr($directory, 1));
            foreach ($segments as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    $fields->fail('the path must not contain empty, "." or ".." segments.'.$hint, 'url');
                }
            }
            $basePath = '/'.Unicode::nfc(implode('/', $segments)).'/';
        }

        return new SiteUrl(strtolower($scheme).'://'.strtolower($authority), $basePath);
    }

    private function author(Fields $fields): ?Author
    {
        if (! $fields->has('author')) {
            return null;
        }

        $value = $fields->raw('author');
        if (is_string($value)) {
            return new Author($fields->requiredString('author'));
        }

        $author = $fields->nested('author');
        if ($author === null) {
            $fields->fail('expected a name or a mapping with name, url and email, got '.Fields::describe($value).'.', 'author');
        }

        $author->rejectUnknown(['name', 'url', 'email']);

        $url = $author->nonEmptyString('url');
        if ($url !== null && ! HttpUrl::isValid($url)) {
            $author->fail('expected an absolute http(s) URL, got "'.$url.'".', 'url');
        }
        $email = $author->nonEmptyString('email');
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $author->fail('"'.$email.'" is not a valid email address.', 'email');
        }

        return new Author($author->requiredString('name'), $url, $email);
    }

    /**
     * @return list<SocialLink>
     */
    private function socialLinks(Fields $fields): array
    {
        $map = $fields->map('social_links');
        if ($map === null) {
            return [];
        }

        $links = [];
        foreach ($map as $key => $value) {
            $key = (string) $key;
            if (! is_string($value) || ! $this->isLinkTarget($value)) {
                $fields->fail(
                    'expected an absolute http(s) URL or a mailto: address, got '.Fields::describe($value).'.',
                    'social_links.'.$key,
                );
            }
            $links[] = new SocialLink($key, self::SOCIAL_LABELS[$key] ?? ucfirst($key), $value);
        }

        return $links;
    }

    private function language(Fields $fields): string
    {
        $language = $fields->nonEmptyString('language') ?? 'en';
        if (preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $language) !== 1) {
            $fields->fail('"'.$language.'" is not a valid language tag (examples: en, tr, pt-BR).', 'language');
        }

        return $language;
    }

    private function timezone(Fields $fields): string
    {
        $timezone = $fields->nonEmptyString('timezone') ?? 'UTC';
        if ($timezone !== 'UTC' && ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $fields->fail('"'.$timezone.'" is not an IANA timezone (examples: UTC, Europe/Istanbul).', 'timezone');
        }

        return $timezone;
    }

    /**
     * @param  Closure(string, ?string): ConfigException  $error
     */
    private function theme(Fields $fields, string $root, Closure $error): ThemeSettings
    {
        return $this->themes->resolve($fields->nested('theme') ?? new Fields([], $error, 'theme'), $root);
    }

    private function isLinkTarget(string $value): bool
    {
        if (str_starts_with(strtolower($value), 'mailto:')) {
            return preg_match('/^mailto:[^\s]+$/i', $value) === 1;
        }

        return HttpUrl::isValid($value);
    }
}
