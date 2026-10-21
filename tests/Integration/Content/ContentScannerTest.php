<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Content;

use Astro\Verse\Content\ContentScanner;
use Astro\Verse\Content\DirectoryLister;
use Astro\Verse\Content\PageBundle;
use Astro\Verse\Content\PageFile;
use Astro\Verse\Content\PlainDir;
use Astro\Verse\Content\SourceTree;
use Astro\Verse\Error\ContentException;
use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Support\Warnings;
use Astro\Verse\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class ContentScannerTest extends TestCase
{
    private const string PAGE = "---\ntitle: T\n---\n";

    private const string YAML = "title: Notes\n";

    /**
     * @param  array<string, string>  $files
     */
    private function scan(array $files, ?Warnings $warnings = null): SourceTree
    {
        return (new ContentScanner)->scan($this->makeSite($files), $warnings ?? new Warnings);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function assertContentError(array $files, string $file, string ...$messageParts): void
    {
        try {
            $this->scan($files);
        } catch (ContentException $exception) {
            $this->assertSame($file, $exception->sourceFile, $exception->getMessage());
            $this->assertStringContainsString('in '.$file, $exception->getMessage());
            foreach ($messageParts as $part) {
                $this->assertStringContainsString($part, $exception->getMessage());
            }

            return;
        }
        $this->fail('Expected a ContentException for '.$file);
    }

    // --- folder-rule errors, one test per row --------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function yamlOutsideBundles(): iterable
    {
        yield 'content root' => ['content/data.yaml'];
        yield 'plain folder' => ['content/legal/data.yaml'];
        yield 'articles folder' => ['content/articles/data.yaml'];
    }

    #[Test]
    #[DataProvider('yamlOutsideBundles')]
    public function row_1_a_yaml_file_has_no_special_meaning_and_fails_like_any_non_markdown_file(string $path): void
    {
        $this->assertContentError(
            [$path => self::YAML, 'content/articles/post/page.md' => self::PAGE],
            $path,
            'only Markdown files can live outside a page bundle',
            'public/',
        );
    }

    #[Test]
    public function row_1_a_yaml_file_inside_a_bundle_is_a_skipped_yaml_file(): void
    {
        $warnings = new Warnings;
        $tree = $this->scan([
            'content/about/page.md' => self::PAGE,
            'content/about/more/data.yaml' => self::YAML,
            'content/articles/post/page.md' => self::PAGE,
            'content/articles/post/data.yaml' => self::YAML,
        ], $warnings);

        $this->assertCount(2, $warnings->all());
        $all = implode("\n", $warnings->all());
        $this->assertStringContainsString('content/about/more/data.yaml: YAML files in a page bundle are ignored', $all);
        $this->assertStringContainsString('content/articles/post/data.yaml: YAML files in a page bundle are ignored', $all);
        $this->assertInstanceOf(PageBundle::class, $tree->articles[0]);
        $this->assertSame([], $tree->articles[0]->attachments);
    }

    #[Test]
    public function row_2_page_md_directly_in_the_articles_folder_fails(): void
    {
        $this->assertContentError(
            ['content/articles/page.md' => self::PAGE],
            'content/articles/page.md',
            'content/articles/ cannot be a page bundle',
            'articles are the .md files and bundles inside it',
        );
    }

    #[Test]
    public function row_3_content_page_md_fails_because_the_home_page_is_generated(): void
    {
        $this->assertContentError(
            ['content/page.md' => self::PAGE],
            'content/page.md',
            'content/ cannot be a page bundle',
            'the home page is generated from content/articles/',
            'Remove content/page.md',
        );
    }

    #[Test]
    public function row_4_articles_subfolder_without_page_md_fails(): void
    {
        $this->assertContentError(
            ['content/articles/loose/notes.txt' => 'x'],
            'content/articles/loose',
            'page bundles',
            'page.md',
        );
    }

    #[Test]
    public function row_5_additional_markdown_inside_a_bundle_fails(): void
    {
        $this->assertContentError(
            [
                'content/about/page.md' => self::PAGE,
                'content/about/extra.md' => self::PAGE,
            ],
            'content/about/extra.md',
            'Markdown is never an attachment',
        );
    }

    #[Test]
    public function row_5_additional_markdown_in_a_bundle_subfolder_fails(): void
    {
        $this->assertContentError(
            [
                'content/about/page.md' => self::PAGE,
                'content/about/img/readme.md' => self::PAGE,
            ],
            'content/about/img/readme.md',
            'Markdown is never an attachment',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonMarkdownOutsideBundles(): iterable
    {
        yield 'content root' => ['content/logo.png'];
        yield 'plain folder' => ['content/legal/terms.pdf'];
        yield 'articles folder' => ['content/articles/cover.jpg'];
        yield 'yaml in plain folder' => ['content/legal/data.yaml'];
        yield 'uppercase extension' => ['content/legal/Readme.MD'];
    }

    #[Test]
    #[DataProvider('nonMarkdownOutsideBundles')]
    public function row_6_non_markdown_file_outside_a_bundle_fails(string $path): void
    {
        $this->assertContentError(
            [$path => 'x'],
            $path,
            'public/',
            'page bundle',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedIndexFiles(): iterable
    {
        yield 'index root' => ['content/index.md'];
        yield '_index root' => ['content/_index.md'];
        yield 'index plain' => ['content/legal/index.md'];
        yield '_index in articles' => ['content/articles/_index.md'];
        yield 'index in bundle' => ['content/about/index.md'];
        yield 'index in bundle subfolder' => ['content/about/img/_index.md'];
    }

    #[Test]
    #[DataProvider('reservedIndexFiles')]
    public function row_7_index_md_and_underscore_index_md_are_reserved(string $path): void
    {
        $this->assertContentError(
            [
                $path => self::PAGE,
                'content/about/page.md' => self::PAGE,
            ],
            $path,
            'reserved',
            'page.md',
        );
    }

    #[Test]
    public function row_8_name_md_and_name_page_md_side_by_side_fail_naming_both_files(): void
    {
        $this->assertContentError(
            [
                'content/about.md' => self::PAGE,
                'content/about/page.md' => self::PAGE,
            ],
            'content/about.md',
            'content/about.md and content/about/page.md',
        );
    }

    #[Test]
    public function row_8_also_applies_inside_the_articles_folder(): void
    {
        $this->assertContentError(
            [
                'content/articles/post.md' => self::PAGE,
                'content/articles/post/page.md' => self::PAGE,
            ],
            'content/articles/post.md',
            'content/articles/post.md and content/articles/post/page.md',
        );
    }

    #[Test]
    public function name_md_next_to_a_plain_folder_of_the_same_name_is_fine(): void
    {
        $tree = $this->scan([
            'content/about.md' => self::PAGE,
            'content/about/team.md' => self::PAGE,
        ]);

        $this->assertCount(2, $tree->children);
    }

    // --- hidden files, symlinks, names ----------------------------------------------------------

    #[Test]
    public function hidden_files_and_folders_are_ignored_everywhere(): void
    {
        $tree = $this->scan([
            'content/.DS_Store' => 'x',
            'content/.hidden.md' => 'not valid',
            'content/.drafts/a.md' => 'x',
            'content/about.md' => self::PAGE,
            'content/articles/.note' => 'x',
            'content/articles/post/page.md' => self::PAGE,
            'content/articles/post/.DS_Store' => 'x',
            'content/articles/post/img/.hidden.png' => 'x',
            'content/articles/post/img/a.png' => 'x',
        ]);

        $this->assertCount(1, $tree->children);
        $this->assertCount(1, $tree->articles);
        $bundle = $tree->articles[0];
        $this->assertInstanceOf(PageBundle::class, $bundle);
        $this->assertSame(['img/a.png'], array_map(static fn ($a) => $a->relativePath, $bundle->attachments));
    }

    #[Test]
    public function a_symlinked_content_folder_is_rejected(): void
    {
        $root = $this->makeSite(['elsewhere/a.md' => self::PAGE]);
        symlink($root.'/elsewhere', $root.'/content');

        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessage('filesystem error in content: symbolic links are not supported');

        (new ContentScanner)->scan($root, new Warnings);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function symlinkLocations(): iterable
    {
        yield 'file at root' => ['content/link.md', 'file'];
        yield 'directory at root' => ['content/linked', 'dir'];
        yield 'file in plain folder' => ['content/legal/link.md', 'file'];
        yield 'file in articles' => ['content/articles/link.md', 'file'];
        yield 'bundle directory in articles' => ['content/articles/linked', 'dir'];
        yield 'attachment in bundle' => ['content/about/photo.png', 'file'];
        yield 'folder in bundle' => ['content/about/images', 'dir'];
    }

    #[Test]
    #[DataProvider('symlinkLocations')]
    public function symlinks_are_rejected_everywhere_in_content(string $link, string $type): void
    {
        $root = $this->makeSite([
            'target/file.md' => self::PAGE,
            'content/legal/terms.md' => self::PAGE,
            'content/articles/post.md' => self::PAGE,
            'content/about/page.md' => self::PAGE,
        ]);
        symlink($type === 'dir' ? $root.'/target' : $root.'/target/file.md', $root.'/'.$link);

        try {
            (new ContentScanner)->scan($root, new Warnings);
        } catch (FilesystemException $exception) {
            $this->assertSame($link, $exception->sourceFile);
            $this->assertStringContainsString('symbolic links are not supported', $exception->getMessage());

            return;
        }
        $this->fail('Expected a FilesystemException');
    }

    #[Test]
    public function names_that_differ_only_in_unicode_normalization_are_rejected(): void
    {
        $nfc = "caf\u{e9}.md";
        $nfd = "cafe\u{301}.md";

        try {
            DirectoryLister::assertNoNfcDuplicates([$nfc, $nfd], 'content');
            $this->fail('Expected a ContentException');
        } catch (ContentException $exception) {
            $this->assertSame('content/'.$nfd, $exception->sourceFile);
            $this->assertStringContainsString($nfc, $exception->getMessage());
            $this->assertStringContainsString($nfd, $exception->getMessage());
            $this->assertStringContainsString('normalization form C', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}|array{string, string, string}>
     */
    public static function badNames(): iterable
    {
        yield 'percent in file' => ['content/100%.md', '%'];
        yield 'question mark' => ['content/what?.md', '?'];
        yield 'hash' => ['content/c#.md', '#'];
        yield 'backslash' => ['content/a\\b.md', '\\'];
        yield 'control character' => ["content/a\u{1}b.md", 'control character'];
        yield 'percent in folder' => ['content/a%20b/c.md', '%', 'content/a%20b'];
        yield 'percent in attachment' => ['content/bundle/page.md', '%'];
    }

    #[Test]
    #[DataProvider('badNames')]
    public function route_forming_names_must_survive_one_decode_encode_round_trip(string $path, string $reason, ?string $reportedPath = null): void
    {
        $files = [$path => self::PAGE];
        if ($path === 'content/bundle/page.md') {
            $files['content/bundle/50%.png'] = 'x';
            $path = 'content/bundle/50%.png';
        }

        $this->assertContentError($files, $reportedPath ?? $path, 'cannot be used in a URL', $reason);
    }

    #[Test]
    public function a_missing_content_folder_is_an_error(): void
    {
        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('content folder does not exist');

        (new ContentScanner)->scan($this->makeSite(['verse.yaml' => 'title: x']), new Warnings);
    }

    // --- bundles, tree shape ---------------------------------------------------------------------

    #[Test]
    public function bundle_yaml_files_are_skipped_with_a_warning_and_never_attachments(): void
    {
        $warnings = new Warnings;
        $tree = $this->scan([
            'content/about/page.md' => self::PAGE,
            'content/about/data.yaml' => 'a: 1',
            'content/about/more/other.yml' => 'a: 1',
            'content/about/diagram.png' => 'x',
        ], $warnings);

        $bundle = $tree->children[0];
        $this->assertInstanceOf(PageBundle::class, $bundle);
        $this->assertSame(['diagram.png'], array_map(static fn ($a) => $a->relativePath, $bundle->attachments));
        $this->assertCount(2, $warnings->all());
        $this->assertStringContainsString('content/about/data.yaml', $warnings->all()[0]);
        $this->assertStringContainsString('content/about/more/other.yml', $warnings->all()[1]);
        $this->assertStringContainsString('ignored', $warnings->all()[0]);
    }

    #[Test]
    public function bundle_attachments_keep_subfolders_and_are_sorted(): void
    {
        $tree = $this->scan([
            'content/post/page.md' => self::PAGE,
            'content/post/z.png' => 'x',
            'content/post/img/b.png' => 'x',
            'content/post/img/a.png' => 'x',
            'content/post/a.txt' => 'x',
        ]);

        $bundle = $tree->children[0];
        $this->assertInstanceOf(PageBundle::class, $bundle);
        $this->assertSame('content/post', $bundle->directory);
        $this->assertSame('content/post/page.md', $bundle->path);
        $this->assertSame(['a.txt', 'img/a.png', 'img/b.png', 'z.png'], array_map(static fn ($a) => $a->relativePath, $bundle->attachments));
        $this->assertSame('content/post/img/a.png', $bundle->attachments[1]->path);
    }

    #[Test]
    public function it_builds_the_tree_with_nfc_route_segments(): void
    {
        $tree = $this->scan([
            'content/about.md' => self::PAGE,
            'content/legal/privacy.md' => self::PAGE,
            'content/çalışma.md' => self::PAGE,
            'content/articles/a.md' => self::PAGE,
            'content/articles/b/page.md' => self::PAGE,
        ]);

        $this->assertCount(3, $tree->children);
        [$about, $legal, $work] = $tree->children;

        $this->assertInstanceOf(PageFile::class, $about);
        $this->assertSame(['about'], $about->segments);
        $this->assertInstanceOf(PlainDir::class, $legal);
        $this->assertSame(['legal'], $legal->segments);
        $this->assertInstanceOf(PageFile::class, $legal->children[0]);
        $this->assertSame(['legal', 'privacy'], $legal->children[0]->segments);
        $this->assertInstanceOf(PageFile::class, $work);
        $this->assertSame(["\u{e7}al\u{131}\u{15f}ma"], $work->segments);

        $this->assertCount(2, $tree->articles);
        $this->assertInstanceOf(PageFile::class, $tree->articles[0]);
        $this->assertSame(['articles', 'a'], $tree->articles[0]->segments);
        $this->assertInstanceOf(PageBundle::class, $tree->articles[1]);
        $this->assertSame(['articles', 'b'], $tree->articles[1]->segments);
    }

    #[Test]
    public function the_articles_folder_is_only_special_directly_below_content(): void
    {
        $tree = $this->scan([
            'content/notes/articles/a.md' => self::PAGE,
            'content/notes/articles/b/page.md' => self::PAGE,
            'content/notes/articles/loose/readme.md' => self::PAGE,
        ]);

        // a plain folder named "articles": ordinary pages, a bundle and a plain subfolder, no article rules
        $this->assertSame([], $tree->articles);
        $notes = $tree->children[0];
        $this->assertInstanceOf(PlainDir::class, $notes);
        $folder = $notes->children[0];
        $this->assertInstanceOf(PlainDir::class, $folder);
        $this->assertSame(['notes', 'articles'], $folder->segments);
        $this->assertCount(3, $folder->children);
        $this->assertInstanceOf(PageFile::class, $folder->children[0]);
        $this->assertInstanceOf(PageBundle::class, $folder->children[1]);
        $this->assertInstanceOf(PlainDir::class, $folder->children[2]);
    }

    #[Test]
    public function the_articles_folder_may_be_missing_or_empty(): void
    {
        $this->assertSame([], $this->scan(['content/about.md' => self::PAGE])->articles);

        $root = $this->makeSite(['content/articles/.keep' => '']);
        $this->assertSame([], (new ContentScanner)->scan($root, new Warnings)->articles);
    }

    #[Test]
    public function traversal_is_sorted_not_filesystem_ordered(): void
    {
        $files = [];
        foreach (['m', 'b', 'z', 'a', 'Q', '10', '9'] as $name) {
            $files["content/{$name}.md"] = self::PAGE;
        }
        $tree = $this->scan($files);

        $names = array_map(static fn ($node) => $node instanceof PageFile ? implode('/', $node->segments) : '?', $tree->children);

        $this->assertSame(['10', '9', 'Q', 'a', 'b', 'm', 'z'], $names);
    }

    #[Test]
    public function an_empty_content_folder_is_a_valid_empty_tree(): void
    {
        $root = $this->makeSite(['verse.yaml' => 'title: x']);
        mkdir($root.'/content');

        $tree = (new ContentScanner)->scan($root, new Warnings);

        $this->assertSame([], $tree->articles);
        $this->assertSame([], $tree->children);
    }
}
