<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Support;

use Astro\Verse\Support\Slug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function slugs(): iterable
    {
        yield 'plain' => ['Hello World', 'hello-world'];
        yield 'already slug' => ['a-b', 'a-b'];
        yield 'punctuation runs' => ['A -- B!!', 'a-b'];
        yield 'trim dashes' => ['--Hello--', 'hello'];
        yield 'digits' => ['PHP 8.4', 'php-8-4'];
        yield 'turkish' => ['Çalışma Notları', 'çalışma-notları'];
        yield 'nfd input is normalized' => ["Cafe\u{301}", "caf\u{e9}"];
        yield 'symbols only' => ['!!!', 'section'];
        yield 'empty' => ['', 'section'];
        yield 'whitespace' => ['   ', 'section'];
        yield 'cjk' => ['日本語 テスト', '日本語-テスト'];
        yield 'underscores' => ['snake_case_words', 'snake-case-words'];
        yield 'csharp' => ['C#', 'c'];
        yield 'combining marks stay inside words' => ['İstanbul', "i\u{307}stanbul"];
        yield 'devanagari vowel signs' => ['हिन्दी', 'हिन्दी'];
    }

    #[Test]
    #[DataProvider('slugs')]
    public function it_slugifies(string $input, string $expected): void
    {
        $this->assertSame($expected, Slug::make($input));
    }
}
