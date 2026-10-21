<?php

declare(strict_types=1);

namespace Astro\Verse\Build;

use Astro\Verse\Site\Page;
use Astro\Verse\Site\SiteModel;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use LogicException;

/**
 * The static search index (`search.json`): one entry per eligible article, newest first, in a deterministic
 * encoding. Pages are not indexed; drafts and scheduled articles are only present because the site model already
 * contains them (a drafts preview), where each entry also carries a `state`.
 */
final readonly class SearchIndex
{
    public const string PATH = 'search.json';

    public function __construct(private SiteModel $site) {}

    public function encode(): string
    {
        $config = $this->site->context->config;
        $zone = $config->dateTimeZone();
        // The same label the timeline shows (ICU `d MMM y` in the site language), so results read like the site.
        $label = new IntlDateFormatter($config->language, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $zone, IntlDateFormatter::GREGORIAN, 'd MMM y');
        $entries = [];
        foreach ($this->site->articles->items as $item) {
            $entries[] = $this->entry($item, $zone, $label);
        }

        return json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * `state` ("draft", "scheduled" or null) is present only in a drafts build; a production index has no such key.
     *
     * @return array{title: string, description: ?string, tags: list<string>, date: string, dateLabel: string, url: string, external: bool, state?: ?string}
     */
    private function entry(Page $item, DateTimeZone $zone, IntlDateFormatter $label): array
    {
        $date = $item->meta->date ?? throw new LogicException('articles always have a date');
        $description = $item->meta->description;

        $entry = [
            'title' => $item->meta->title,
            'description' => $description === null || trim($description) === '' ? null : $description,
            'tags' => $item->meta->tags,
            'date' => DateTimeImmutable::createFromInterface($date)->setTimezone($zone)->format('Y-m-d'),
            'dateLabel' => (string) $label->format($date->getTimestamp()),
            'url' => $item->route === null ? (string) $item->meta->externalUrl : $this->site->urls->page($item->route),
            'external' => $item->route === null,
        ];
        if ($this->site->context->drafts) {
            $entry['state'] = $item->isUnpublished() ? $item->state->value : null;
        }

        return $entry;
    }
}
