<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Site;

use Astro\Verse\Site\Listing;
use Astro\Verse\Site\Page;
use Astro\Verse\Tests\Support\PageFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ListingTest extends TestCase
{
    /**
     * @return list<Page>
     */
    private static function items(int $count): array
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = PageFactory::article('/articles/p'.$i.'/');
        }

        return $items;
    }

    #[Test]
    public function an_empty_listing_is_one_page_without_navigation(): void
    {
        $listing = Listing::paginate('/articles/', [], 10);

        $this->assertSame(1, $listing->pageCount());
        $this->assertSame(0, $listing->itemCount());
        $page = $listing->pages[0];
        $this->assertSame(1, $page->number);
        $this->assertSame('/articles/', $page->route);
        $this->assertSame([], $page->items);
        $this->assertSame(1, $page->pageCount);
        $this->assertSame(0, $page->itemCount);
        $this->assertNull($page->previousRoute);
        $this->assertNull($page->nextRoute);
    }

    #[Test]
    public function fewer_items_than_per_page_make_one_page(): void
    {
        $listing = Listing::paginate('/articles/', self::items(3), 10);

        $this->assertSame(1, $listing->pageCount());
        $this->assertCount(3, $listing->pages[0]->items);
        $this->assertNull($listing->pages[0]->nextRoute);
    }

    #[Test]
    public function an_exact_multiple_has_no_phantom_page(): void
    {
        $listing = Listing::paginate('/articles/', self::items(4), 2);

        $this->assertSame(2, $listing->pageCount());
        $this->assertSame([2, 2], array_map(static fn ($page) => count($page->items), $listing->pages));
    }

    #[Test]
    public function a_remainder_makes_a_short_last_page_with_navigation(): void
    {
        $listing = Listing::paginate('/articles/', self::items(5), 2);

        $this->assertSame(3, $listing->pageCount());
        $this->assertSame([2, 2, 1], array_map(static fn ($page) => count($page->items), $listing->pages));
        $this->assertSame(['/articles/', '/articles/page/2/', '/articles/page/3/'], array_map(static fn ($page) => $page->route, $listing->pages));

        [$first, $second, $third] = $listing->pages;
        $this->assertNull($first->previousRoute);
        $this->assertSame('/articles/page/2/', $first->nextRoute);
        $this->assertSame('/articles/', $second->previousRoute);
        $this->assertSame('/articles/page/3/', $second->nextRoute);
        $this->assertSame('/articles/page/2/', $third->previousRoute);
        $this->assertNull($third->nextRoute);

        foreach ($listing->pages as $page) {
            $this->assertSame(3, $page->pageCount);
            $this->assertSame(5, $page->itemCount);
        }
        $this->assertSame('/articles/p3/', $second->items[0]->route);
    }

    #[Test]
    public function pagination_works_for_the_articles_and_tag_routes(): void
    {
        $articles = Listing::paginate('/articles/', self::items(3), 2);
        $tag = Listing::paginate('/tags/php/', self::items(3), 2);

        $this->assertSame('/articles/page/2/', $articles->pages[1]->route);
        $this->assertSame('/articles/', $articles->pages[1]->previousRoute);
        $this->assertSame('/tags/php/page/2/', $tag->pages[1]->route);
    }
}
