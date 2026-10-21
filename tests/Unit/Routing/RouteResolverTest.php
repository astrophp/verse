<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Routing;

use Astro\Verse\Routing\RouteResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, string, string}> segments, route, output path
     */
    public static function sourceRoutes(): iterable
    {
        yield 'content/about.md' => [['about'], '/about/', 'about/index.html'];
        yield 'content/about/page.md' => [['about'], '/about/', 'about/index.html'];
        yield 'content/legal/privacy.md' => [['legal', 'privacy'], '/legal/privacy/', 'legal/privacy/index.html'];
        yield 'article.md' => [['articles', 'article'], '/articles/article/', 'articles/article/index.html'];
        yield 'article/page.md' => [['articles', 'article'], '/articles/article/', 'articles/article/index.html'];
        yield 'unicode' => [['çalışma'], '/çalışma/', 'çalışma/index.html'];
    }

    /**
     * @param  list<string>  $segments
     */
    #[Test]
    #[DataProvider('sourceRoutes')]
    public function it_derives_routes_and_output_paths_from_source_segments(array $segments, string $route, string $output): void
    {
        $this->assertSame($route, RouteResolver::resolve($segments, null, null));
        $this->assertSame($route, RouteResolver::fromSegments($segments));
        $this->assertSame($output, RouteResolver::outputPath($route));
    }

    #[Test]
    public function slug_replaces_only_the_last_segment(): void
    {
        $this->assertSame('/notes/hello/', RouteResolver::resolve(['notes', 'my-long-file-name'], 'hello', null));
        $this->assertSame('/hello/', RouteResolver::resolve(['about'], 'hello', null));
    }

    #[Test]
    public function permalink_replaces_the_whole_route_and_beats_slug(): void
    {
        $this->assertSame('/custom/spot/', RouteResolver::resolve(['notes', 'a'], 'ignored', '/custom/spot/'));
        $this->assertSame('/custom/', RouteResolver::resolve(['notes', 'a'], null, '/custom/'));
    }

    #[Test]
    public function it_builds_pagination_and_tag_routes(): void
    {
        $this->assertSame('/notes/', RouteResolver::pageRoute('/notes/', 1));
        $this->assertSame('/notes/page/2/', RouteResolver::pageRoute('/notes/', 2));
        $this->assertSame('/page/3/', RouteResolver::pageRoute('/', 3));
        $this->assertSame('/notes/tags/php/page/2/', RouteResolver::pageRoute('/notes/tags/php/', 2));
        $this->assertSame('/tags/', RouteResolver::tagIndexRoute());
        $this->assertSame('/tags/php/', RouteResolver::tagRoute('php'));
        $this->assertSame('/tags/php/page/2/', RouteResolver::pageRoute(RouteResolver::tagRoute('php'), 2));
        $this->assertSame('page/2/index.html', RouteResolver::outputPath('/page/2/'));
        $this->assertSame('tags/index.html', RouteResolver::outputPath('/tags/'));
    }

    #[Test]
    public function attachments_live_next_to_the_final_route(): void
    {
        $this->assertSame('notes/post/img/a.png', RouteResolver::relativeOutputPath('/notes/post/', 'img/a.png'));
        $this->assertSame('x/y/a.png', RouteResolver::relativeOutputPath('/x/y/', 'a.png'));
    }
}
