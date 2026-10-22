<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Support;

use Astro\Verse\Support\HttpUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HttpUrlTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validUrls(): iterable
    {
        yield 'https' => ['https://example.test/post'];
        yield 'http with port' => ['http://localhost:8000/'];
        yield 'query and fragment' => ['https://example.test/a?b=1&c="d"#e'];
        yield 'unicode host' => ['https://çalışma.example/'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'trailing newline after the path' => ["https://example.test/post\n"];
        yield 'trailing newline after the host' => ["https://example.test\n"];
        yield 'trailing newline after the port' => ["https://example.test:8080\n"];
        yield 'trailing space' => ['https://example.test/post '];
        yield 'leading space' => [' https://example.test/post'];
        yield 'inner newline' => ["https://example.test/a\nb"];
        yield 'no host' => ['https://'];
        yield 'relative' => ['/post/'];
        yield 'ftp' => ['ftp://example.test/'];
    }

    #[Test]
    #[DataProvider('validUrls')]
    public function it_accepts_absolute_http_urls(string $url): void
    {
        $this->assertTrue(HttpUrl::isValid($url));
    }

    #[Test]
    #[DataProvider('invalidUrls')]
    public function it_rejects_anything_else_including_surrounding_whitespace(string $url): void
    {
        $this->assertFalse(HttpUrl::isValid($url));
    }

    #[Test]
    public function an_authority_with_a_trailing_newline_is_invalid(): void
    {
        $this->assertTrue(HttpUrl::isValidAuthority('example.test:8080'));
        $this->assertFalse(HttpUrl::isValidAuthority("example.test\n"));
        $this->assertFalse(HttpUrl::isValidAuthority("example.test:8080\n"));
    }
}
