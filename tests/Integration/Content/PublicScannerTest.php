<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Content;

use Astro\Verse\Content\PublicScanner;
use Astro\Verse\Error\ContentException;
use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PublicScannerTest extends TestCase
{
    #[Test]
    public function no_public_folder_means_no_files(): void
    {
        $this->assertSame([], (new PublicScanner)->scan($this->makeSite(['verse.yaml' => 'title: x'])));
    }

    #[Test]
    public function it_lists_files_sorted_with_output_paths(): void
    {
        $files = (new PublicScanner)->scan($this->makeSite([
            'public/robots.txt' => 'x',
            'public/img/b.png' => 'x',
            'public/img/a.png' => 'x',
            'public/favicon.ico' => 'x',
        ]));

        $this->assertSame(
            ['favicon.ico', 'img/a.png', 'img/b.png', 'robots.txt'],
            array_map(static fn ($file) => $file->outputPath, $files),
        );
        $this->assertSame('public/img/a.png', $files[1]->path);
    }

    #[Test]
    public function hidden_files_are_skipped_except_under_well_known(): void
    {
        $files = (new PublicScanner)->scan($this->makeSite([
            'public/.DS_Store' => 'x',
            'public/.hidden/a.txt' => 'x',
            'public/.well-known/security.txt' => 'x',
            'public/.well-known/acme/.dotfile' => 'x',
            'public/ok.txt' => 'x',
        ]));

        $this->assertSame(
            ['.well-known/acme/.dotfile', '.well-known/security.txt', 'ok.txt'],
            array_map(static fn ($file) => $file->outputPath, $files),
        );
    }

    #[Test]
    public function symlinks_are_rejected(): void
    {
        $root = $this->makeSite(['public/a.txt' => 'x', 'target.txt' => 'x']);
        symlink($root.'/target.txt', $root.'/public/link.txt');

        try {
            (new PublicScanner)->scan($root);
            $this->fail('Expected a FilesystemException');
        } catch (FilesystemException $exception) {
            $this->assertSame('public/link.txt', $exception->sourceFile);
        }
    }

    #[Test]
    public function a_symlinked_public_folder_is_rejected(): void
    {
        $root = $this->makeSite(['elsewhere/a.txt' => 'x']);
        symlink($root.'/elsewhere', $root.'/public');

        $this->expectException(FilesystemException::class);

        (new PublicScanner)->scan($root);
    }

    #[Test]
    public function a_hidden_symlink_that_is_not_copied_is_ignored(): void
    {
        $root = $this->makeSite(['public/a.txt' => 'x', 'target.txt' => 'x']);
        symlink($root.'/target.txt', $root.'/public/.hidden-link');

        $this->assertCount(1, (new PublicScanner)->scan($root));
    }

    #[Test]
    public function names_that_break_the_url_contract_are_rejected(): void
    {
        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('public/100%.txt');

        (new PublicScanner)->scan($this->makeSite(['public/100%.txt' => 'x']));
    }
}
