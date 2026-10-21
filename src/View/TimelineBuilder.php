<?php

declare(strict_types=1);

namespace Astro\Verse\View;

use Astro\Verse\Routing\RouteResolver;
use Astro\Verse\Site\ListingPage;
use Astro\Verse\Site\Page;
use Astro\Verse\Site\PageRole;
use Astro\Verse\Support\Slug;
use Astro\Verse\View\Model\Archive;
use Astro\Verse\View\Model\Link;
use Astro\Verse\View\Model\Pagination;
use Astro\Verse\View\Model\TagRef;
use Astro\Verse\View\Model\TimelineEntry;
use Astro\Verse\View\Model\TimelineGroup;
use LogicException;

/**
 * Turns articles into the month-grouped timeline of the home and listing views, with count and pagination for listings.
 */
final readonly class TimelineBuilder
{
    public function __construct(private ThemeContext $theme) {}

    /**
     * A full listing page: optional toolbar, count, month-grouped list and pagination.
     *
     * @param  ?string  $currentTag  slug of the tag whose archive this is, to mark that tag in the entries
     */
    public function archive(ListingPage $page, ?string $sectionLabel, ?string $currentTag = null): Archive
    {
        return new Archive(
            $sectionLabel,
            $page->itemCount.($page->itemCount === 1 ? ' article' : ' articles'),
            $this->groups($page->items, $currentTag),
            $page->pageCount > 1
                ? new Pagination(
                    $page->previousRoute === null ? null : new Link('Newer', $this->theme->url($page->previousRoute)),
                    $page->nextRoute === null ? null : new Link('Older', $this->theme->url($page->nextRoute)),
                    'Page '.$page->number.' of '.$page->pageCount,
                )
                : null,
        );
    }

    /**
     * The home page's short list: toolbar and month-grouped list, without count or pagination.
     *
     * @param  list<Page>  $items
     */
    public function latest(array $items, string $sectionLabel): Archive
    {
        return new Archive($sectionLabel, null, $this->groups($items), null);
    }

    /**
     * Contiguous runs of the same month (in the site language and timezone), in the order given.
     *
     * @param  list<Page>  $items
     * @return list<TimelineGroup>
     */
    private function groups(array $items, ?string $currentTag = null): array
    {
        $groups = [];
        $key = null;
        $label = '';
        $fullLabel = '';
        $entries = [];
        foreach ($items as $item) {
            $date = $item->meta->date ?? throw new LogicException('articles always have a date');
            $itemKey = $this->theme->formatDate($date, 'y-MM');
            if ($itemKey !== $key && $entries !== []) {
                $groups[] = new TimelineGroup($label, $fullLabel, $entries);
                $entries = [];
            }
            if ($itemKey !== $key) {
                $key = $itemKey;
                $label = $this->theme->formatDate($date, 'y MMM');
                $fullLabel = $this->theme->formatDate($date, 'MMMM y');
            }
            $entries[] = $this->entry($item, $currentTag);
        }
        if ($entries !== []) {
            $groups[] = new TimelineGroup($label, $fullLabel, $entries);
        }

        return $groups;
    }

    public function entry(Page $page, ?string $currentTag = null): TimelineEntry
    {
        $date = $page->meta->date ?? throw new LogicException('articles always have a date');

        return new TimelineEntry(
            title: $page->meta->title,
            description: self::text($page->meta->description),
            url: $page->route === null ? (string) $page->meta->externalUrl : $this->theme->url($page->route),
            external: $page->isExternal(),
            isoDate: $this->theme->isoDate($date),
            dateLabel: $this->theme->formatDate($date, 'd MMM y'),
            stateLabel: self::stateLabel($page),
            tags: $this->tags($page, $currentTag),
        );
    }

    /**
     * An article's tags as links to the tag archives; a page's tags (not shown by the default theme) stay plain labels.
     *
     * @param  ?string  $currentTag  slug of the tag being browsed, which is marked current
     * @return list<TagRef>
     */
    public function tags(Page $page, ?string $currentTag = null): array
    {
        $linked = $page->role === PageRole::Article;

        $tags = [];
        foreach ($page->meta->tags as $label) {
            $slug = Slug::make($label);
            $tags[] = new TagRef(
                $label,
                $linked ? $this->theme->url(RouteResolver::tagRoute($slug)) : null,
                $linked && $currentTag !== null && $slug === $currentTag,
            );
        }

        return $tags;
    }

    public static function stateLabel(Page $page): ?string
    {
        return match ($page->state->value) {
            'draft' => 'Draft',
            'scheduled' => 'Scheduled',
            default => null,
        };
    }

    private static function text(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
