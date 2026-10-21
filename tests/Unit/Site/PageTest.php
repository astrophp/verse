<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Site;

use Astro\Verse\Tests\Support\PageFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    #[Test]
    public function external_items_have_no_route_and_tie_on_their_url(): void
    {
        $page = PageFactory::article('/x/', externalUrl: 'https://example.org/post');

        $this->assertTrue($page->isExternal());
        $this->assertNull($page->route);
        $this->assertSame('https://example.org/post', $page->tieKey());
    }

    #[Test]
    public function local_items_are_not_external(): void
    {
        $page = PageFactory::article('/x/');

        $this->assertFalse($page->isExternal());
        $this->assertSame('/x/', $page->tieKey());
    }
}
