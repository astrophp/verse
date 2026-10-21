<?php

declare(strict_types=1);

namespace Astro\Verse\Site;

/**
 * The articles of `content/articles/`, their archive at `/articles/` and their tags. Everything about them is
 * fixed: newest first, ten per page, tag pages under `/tags/`, the five newest on the home page.
 */
final readonly class Articles
{
    /** The route of the article archive. */
    public const string ROUTE = '/articles/';

    /** Articles per archive page, in the archive and on tag archives. */
    public const int PER_PAGE = 10;

    /** Articles shown in "Latest articles" on the home page. */
    public const int LATEST = 5;

    /** The "Articles" menu item and the name used in links and counts. */
    public const string TITLE = 'Articles';

    /**
     * @param  list<Page>  $items  eligible articles, newest first
     * @param  Listing  $listing  the archive: every article, paginated; a single empty page without articles
     * @param  list<Tag>  $tags  sorted by label
     */
    public function __construct(
        public array $items,
        public Listing $listing,
        public array $tags,
    ) {}

    /**
     * The newest articles, for the home page.
     *
     * @return list<Page>
     */
    public function latest(): array
    {
        return array_slice($this->items, 0, self::LATEST);
    }
}
