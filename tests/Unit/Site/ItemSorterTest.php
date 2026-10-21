<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Site;

use Astro\Verse\Site\ItemSorter;
use Astro\Verse\Site\Page;
use Astro\Verse\Tests\Support\PageFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ItemSorterTest extends TestCase
{
    /**
     * @param  list<Page>  $pages
     * @return list<string>
     */
    private static function routes(array $pages): array
    {
        return array_map(static fn (Page $page) => $page->tieKey(), $pages);
    }

    #[Test]
    public function it_sorts_newest_first(): void
    {
        $pages = [
            PageFactory::article('/b/', date: '2026-02-01'),
            PageFactory::article('/a/', date: '2026-03-01'),
            PageFactory::article('/c/', date: '2026-01-01'),
        ];

        $this->assertSame(['/a/', '/b/', '/c/'], self::routes(ItemSorter::newestFirst($pages)));
    }

    #[Test]
    public function it_compares_timestamps_not_dates(): void
    {
        $pages = [
            PageFactory::article('/morning/', date: '2026-02-01T08:00:00'),
            PageFactory::article('/evening/', date: '2026-02-01T20:00:00'),
        ];

        $this->assertSame(['/evening/', '/morning/'], self::routes(ItemSorter::newestFirst($pages)));
    }

    #[Test]
    public function ties_break_by_route_ascending(): void
    {
        $pages = [
            PageFactory::article('/articles/c/', date: '2026-01-01'),
            PageFactory::article('/articles/a/', date: '2026-01-01'),
            PageFactory::article('/articles/b/', date: '2026-01-01'),
        ];

        $this->assertSame(['/articles/a/', '/articles/b/', '/articles/c/'], self::routes(ItemSorter::newestFirst($pages)));
    }

    #[Test]
    public function external_items_tie_break_by_external_url(): void
    {
        $pages = [
            PageFactory::article('/x/', date: '2026-01-01', externalUrl: 'https://b.test/'),
            PageFactory::article('/y/', date: '2026-01-01', externalUrl: 'https://a.test/'),
            PageFactory::article('/m/', date: '2026-01-01'),
        ];

        // an external item is compared by its external URL, a local one by its route
        $this->assertSame(['/m/', 'https://a.test/', 'https://b.test/'], self::routes(ItemSorter::newestFirst($pages)));
    }

    #[Test]
    public function the_result_does_not_depend_on_input_order(): void
    {
        $pages = [
            PageFactory::article('/a/', date: '2026-01-01'),
            PageFactory::article('/b/', date: '2026-01-01'),
            PageFactory::article('/c/', date: '2026-02-01'),
        ];

        $forward = self::routes(ItemSorter::newestFirst($pages));
        $backward = self::routes(ItemSorter::newestFirst(array_reverse($pages)));

        $this->assertSame($forward, $backward);
        $this->assertSame(['/c/', '/a/', '/b/'], $forward);
    }
}
