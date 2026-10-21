<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Site;

use Astro\Verse\Site\Menu;
use Astro\Verse\Site\MenuItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MenuTest extends TestCase
{
    #[Test]
    public function ordered_items_come_first_then_alphabetical_by_title_then_route(): void
    {
        $menu = Menu::of([
            new MenuItem('Zeta', '/zeta/', null),
            new MenuItem('About', '/about/', 3),
            new MenuItem('alpha', '/b/', null),
            new MenuItem('Alpha', '/a/', null),
            new MenuItem('Notes', '/notes/', 1),
            new MenuItem('Projects', '/projects/', 2),
            new MenuItem('Same order B', '/sb/', 5),
            new MenuItem('Same order A', '/sa/', 5),
        ]);

        $this->assertSame(
            ['/notes/', '/projects/', '/about/', '/sa/', '/sb/', '/a/', '/b/', '/zeta/'],
            array_map(static fn (MenuItem $item) => $item->route, $menu->items),
        );
    }

    #[Test]
    public function a_negative_order_sorts_before_positive(): void
    {
        $menu = Menu::of([new MenuItem('B', '/b/', 1), new MenuItem('A', '/a/', -1), new MenuItem('C', '/c/', 0)]);

        $this->assertSame(['/a/', '/c/', '/b/'], array_map(static fn (MenuItem $item) => $item->route, $menu->items));
    }

    #[Test]
    public function the_current_item_is_the_longest_prefix_of_the_route(): void
    {
        $menu = Menu::of([
            new MenuItem('Notes', '/notes/', 1),
            new MenuItem('Series', '/notes/series/', 2),
            new MenuItem('About', '/about/', 3),
        ]);

        $this->assertSame('/notes/', $menu->current('/notes/')?->route);
        $this->assertSame('/notes/', $menu->current('/notes/an-article/')?->route);
        $this->assertSame('/notes/', $menu->current('/notes/page/2/')?->route);
        $this->assertSame('/notes/', $menu->current('/notes/tags/php/')?->route);
        $this->assertSame('/notes/series/', $menu->current('/notes/series/part-one/')?->route);
        $this->assertSame('/about/', $menu->current('/about/')?->route);
        $this->assertNull($menu->current('/about-us/'));
        $this->assertNull($menu->current('/legal/privacy/'));
        $this->assertNull($menu->current('/'));
    }

    #[Test]
    public function the_home_item_only_matches_the_home_route(): void
    {
        $menu = Menu::of([new MenuItem('Home', '/', 1), new MenuItem('About', '/about/', 2)]);

        $this->assertSame('/', $menu->current('/')?->route);
        $this->assertSame('/about/', $menu->current('/about/')?->route);
        $this->assertNull($menu->current('/other/'));
        $this->assertNull($menu->current('/page/2/'));
    }

    #[Test]
    public function an_empty_menu_has_no_current_item(): void
    {
        $this->assertNull(Menu::of([])->current('/'));
        $this->assertSame([], Menu::of([])->items);
    }

    #[Test]
    public function leading_items_come_first_in_the_order_given_then_the_sorted_pages(): void
    {
        $menu = Menu::of(
            [
                new MenuItem('About', '/about/', 3),
                new MenuItem('Projects', '/projects/', 1),
                new MenuItem('Alpha', '/alpha/', null),
            ],
            [new MenuItem('Articles', '/articles/', null), new MenuItem('Tags', '/tags/', null)],
        );

        $this->assertSame(
            ['/articles/', '/tags/', '/projects/', '/about/', '/alpha/'],
            array_map(static fn (MenuItem $item) => $item->route, $menu->items),
        );
        $this->assertSame('/tags/', $menu->current('/tags/php/')?->route);
        $this->assertSame('/articles/', $menu->current('/articles/')?->route);
        $this->assertSame('/articles/', $menu->current('/articles/page/2/')?->route);
        $this->assertNull($menu->current('/'));
    }
}
