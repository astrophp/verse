<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Routing;

use Astro\Verse\Routing\UrlGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UrlGeneratorTest extends TestCase
{
    #[Test]
    public function it_encodes_each_segment_exactly_once(): void
    {
        $urls = new UrlGenerator;

        $this->assertSame('/', $urls->page('/'));
        $this->assertSame('/about/', $urls->page('/about/'));
        $this->assertSame('/my%20page/', $urls->page('/my page/'));
        $this->assertSame('/%C3%A7al%C4%B1%C5%9Fma/', $urls->page('/çalışma/'));
        $this->assertSame('/articles/%C3%A7al%C4%B1%C5%9Fma/page/2/', $urls->page('/articles/çalışma/page/2/'));
        $this->assertSame('/a%26b/', $urls->page('/a&b/'));
    }

    #[Test]
    public function it_prefixes_the_base_path(): void
    {
        $urls = new UrlGenerator('/notes/');

        $this->assertSame('/notes/', $urls->page('/'));
        $this->assertSame('/notes/about/', $urls->page('/about/'));
        $this->assertSame('/notes/assets/theme/app.css', $urls->asset('assets/theme/app.css'));
        $this->assertSame('/notes/articles/post/my%20image.png', $urls->asset('articles/post/my image.png'));
        $this->assertSame('/notes/about/', $urls->preview('/about/'));
    }

    #[Test]
    public function it_encodes_a_unicode_base_path_once(): void
    {
        $this->assertSame('/%C3%A7/x/', (new UrlGenerator('/ç/'))->page('/x/'));
    }

    #[Test]
    public function asset_paths_have_no_trailing_slash_added(): void
    {
        $this->assertSame('/favicon.ico', (new UrlGenerator)->asset('favicon.ico'));
        $this->assertSame('/favicon.ico', (new UrlGenerator)->asset('/favicon.ico'));
    }

    #[Test]
    public function canonical_urls_use_the_configured_origin_and_base_path(): void
    {
        $urls = new UrlGenerator('/notes/', 'https://example.test');

        $this->assertSame('https://example.test/notes/', $urls->canonical('/'));
        $this->assertSame('https://example.test/notes/%C3%A7/', $urls->canonical('/ç/'));
    }

    #[Test]
    public function canonical_is_null_without_a_configured_url(): void
    {
        $this->assertNull((new UrlGenerator)->canonical('/about/'));
    }
}
