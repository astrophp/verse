<?php

declare(strict_types=1);

namespace Astro\Verse\Build;

use Astro\Verse\Error\ContentException;
use Astro\Verse\Site\Page;
use Astro\Verse\Site\SiteModel;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;

/**
 * The production `feed.xml`: an Atom feed (RFC 4287) of the 20 newest articles, summary only (title, link, dates,
 * description and tags; never the article content). External articles link to their `external_url`. Drafts and
 * scheduled articles are never in a production site model. An entry's `id` is its URL, so changing `url`, a slug or
 * permalink, or an `external_url` makes feed readers see a new entry; two entries with the same URL are an error.
 */
final readonly class Feed
{
    public const string PATH = 'feed.xml';

    public const int LIMIT = 20;

    public function __construct(private SiteModel $site) {}

    public function xml(): string
    {
        $config = $this->site->context->config;
        $home = $this->canonical('/');

        $entries = '';
        $updated = null;
        /** @var array<string, string> $ids entry URL => source path of the first article that uses it */
        $ids = [];
        foreach (array_slice($this->site->articles->items, 0, self::LIMIT) as $page) {
            $url = self::clean($this->entryUrl($page));
            if (isset($ids[$url])) {
                throw new ContentException(
                    'has the same feed id as '.$ids[$url].' ('.$url.'): feed readers would merge them. '
                    .'Point "external_url" elsewhere or remove one of the articles.',
                    $page->sourcePath,
                );
            }
            $ids[$url] = $page->sourcePath;

            $published = $this->localTime($page->meta->date ?? $this->site->context->now);
            $modified = $page->meta->updated === null ? $published : $this->localTime($page->meta->updated);
            if ($updated === null || $modified > $updated) {
                $updated = $modified;
            }

            $entries .= $this->entry($page, $url, $published, $modified);
        }

        $author = $config->author;
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            .'<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="'.self::escape($config->language)."\">\n"
            .'  <title>'.self::escape($config->title)."</title>\n";
        if ($config->description !== null && trim($config->description) !== '') {
            $xml .= '  <subtitle>'.self::escape($config->description)."</subtitle>\n";
        }
        $xml .= '  <link rel="alternate" type="text/html" href="'.self::escape($home)."\"/>\n"
            .'  <link rel="self" type="application/atom+xml" href="'.self::escape($home.self::PATH)."\"/>\n"
            .'  <id>'.self::escape($home)."</id>\n"
            .'  <updated>'.self::escape(($updated ?? $this->localTime($this->site->context->now))->format(DATE_ATOM))."</updated>\n"
            ."  <author>\n"
            .'    <name>'.self::escape($author->name ?? $config->title)."</name>\n";
        if ($author?->url !== null) {
            $xml .= '    <uri>'.self::escape($author->url)."</uri>\n";
        }
        if ($author?->email !== null) {
            $xml .= '    <email>'.self::escape($author->email)."</email>\n";
        }
        $xml .= "  </author>\n"
            .'  <generator uri="https://github.com/astrophp/verse" version="'.self::escape($this->site->context->version).'">Verse</generator>'."\n";

        return $xml.$entries."</feed>\n";
    }

    private function entry(Page $page, string $url, DateTimeImmutable $published, DateTimeImmutable $updated): string
    {
        $meta = $page->meta;

        $xml = "  <entry>\n"
            .'    <title type="text">'.self::escape($meta->title)."</title>\n"
            .'    <link rel="alternate" type="text/html" href="'.self::escape($url)."\"/>\n"
            .'    <id>'.self::escape($url)."</id>\n"
            .'    <published>'.self::escape($published->format(DATE_ATOM))."</published>\n"
            .'    <updated>'.self::escape($updated->format(DATE_ATOM))."</updated>\n";
        if ($meta->description !== null && trim($meta->description) !== '') {
            $xml .= '    <summary type="text">'.self::escape($meta->description)."</summary>\n";
        }
        foreach ($meta->tags as $tag) {
            $xml .= '    <category term="'.self::escape($tag)."\"/>\n";
        }

        return $xml."  </entry>\n";
    }

    /**
     * A local article links to its own page (its `canonical` is not consulted), an external one to its `external_url`.
     */
    private function entryUrl(Page $page): string
    {
        return $page->route === null ? (string) $page->meta->externalUrl : $this->canonical($page->route);
    }

    private function canonical(string $route): string
    {
        return $this->site->urls->canonical($route) ?? throw self::missingUrl();
    }

    private function localTime(DateTimeInterface $date): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone($this->site->context->config->dateTimeZone());
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars(self::clean($value), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The text as it appears in the feed: invalid UTF-8 replaced and characters XML 1.0 forbids removed.
     */
    private static function clean(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');

        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
    }

    private static function missingUrl(): LogicException
    {
        return new LogicException('a feed needs the configured `url`; production builds always have one.');
    }
}
