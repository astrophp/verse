<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit;

use Astro\Verse\Verse;
use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VerseTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function versions(): iterable
    {
        yield 'tag' => ['v0.2.0', 'f8d2498bd65da4698265290a21635fbc485d17e9', '0.2.0'];
        yield 'tag without v' => ['0.2.0', null, '0.2.0'];
        yield 'pre-release tag' => ['v0.3.0-rc.1', 'abc', '0.3.0-rc.1'];
        yield 'branch' => ['dev-main', 'f8d2498bd65da4698265290a21635fbc485d17e9', 'dev-main (f8d2498)'];
        yield 'branch without reference' => ['dev-main', null, 'dev-main'];
        yield 'branch with an empty reference' => ['dev-feature/x', '', 'dev-feature/x'];
        yield 'branch alias' => ['0.3.x-dev', 'f8d2498bd65da4698265290a21635fbc485d17e9', '0.3.x-dev (f8d2498)'];
        yield 'branch named like a version' => ['dev-v2', 'abcdef1234', 'dev-v2 (abcdef1)'];
        yield 'no version set' => ['1.0.0+no-version-set', null, 'unknown'];
        yield 'not installed' => [null, null, 'unknown'];
        yield 'empty' => ['', null, 'unknown'];
    }

    #[Test]
    #[DataProvider('versions')]
    public function it_formats_the_composer_version(?string $pretty, ?string $reference, string $expected): void
    {
        $this->assertSame($expected, Verse::formatVersion($pretty, $reference));
    }

    #[Test]
    public function it_reads_the_version_composer_installed(): void
    {
        $this->assertTrue(InstalledVersions::isInstalled(Verse::PACKAGE));
        $this->assertSame(
            Verse::formatVersion(InstalledVersions::getPrettyVersion(Verse::PACKAGE), InstalledVersions::getReference(Verse::PACKAGE)),
            Verse::version(),
        );
    }
}
