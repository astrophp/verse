<?php

declare(strict_types=1);

namespace Astro\Verse\Content;

use Astro\Verse\Error\ContentException;
use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Support\Warnings;

/**
 * Walks `content/` and applies the folder rules (articles folder, page bundles, plain folders).
 * Deterministic: entries are sorted by NFC name, never in filesystem order.
 */
final class ContentScanner
{
    private const string PAGE = 'page.md';

    /** The folder below `content/` whose Markdown files are the articles; its name and place are fixed. */
    private const string ARTICLES = 'articles';

    public function __construct(
        private readonly DirectoryLister $lister = new DirectoryLister,
    ) {}

    public function scan(string $root, Warnings $warnings): SourceTree
    {
        $absolute = rtrim($root, '/').'/content';
        if (is_link($absolute)) {
            throw new FilesystemException('symbolic links are not supported. Replace the link with the real folder.', 'content');
        }
        if (! is_dir($absolute)) {
            throw new ContentException('the content folder does not exist. Create it, or run `verse init`.', 'content');
        }

        $entries = $this->lister->list($absolute, 'content');
        $this->assertNoReservedNames($entries);

        $articles = [];
        $rest = [];
        foreach ($entries as $entry) {
            if ($entry->isDirectory && $entry->nfc === self::ARTICLES) {
                $articles = $this->scanArticles($entry, $warnings);
            } elseif (! $entry->isDirectory && $entry->name === self::PAGE) {
                throw new ContentException(
                    'content/ cannot be a page bundle: the home page is generated from content/articles/. '
                    .'Remove content/page.md.',
                    $entry->path,
                );
            } else {
                $rest[] = $entry;
            }
        }

        return new SourceTree($articles, $this->scanContainer($rest, [], false, $warnings));
    }

    /**
     * Children of the site root or a plain folder.
     *
     * @param  list<DirEntry>  $entries
     * @param  list<string>  $segments
     * @return list<PageFile|PageBundle|PlainDir>
     */
    private function scanContainer(array $entries, array $segments, bool $inArticles, Warnings $warnings): array
    {
        $this->assertNoReservedNames($entries);

        $pages = [];
        $children = [];
        foreach ($entries as $entry) {
            if ($entry->isDirectory) {
                $children[] = $this->scanDirectory($entry, $segments, $inArticles, $warnings);

                continue;
            }

            if (! str_ends_with($entry->nfc, '.md')) {
                throw new ContentException(
                    'only Markdown files can live outside a page bundle. Move it to public/ (copied as-is), '
                    .'or turn its folder into a page bundle by naming the page file page.md.',
                    $entry->path,
                );
            }

            $stem = substr($entry->nfc, 0, -3);
            $this->assertRouteName($stem, $entry->path, 'file name');
            $page = new PageFile($entry->path, [...$segments, $stem]);
            $pages[$stem] = $page;
            $children[] = $page;
        }

        $this->assertNoSideBySide($entries, $pages);

        return $children;
    }

    /**
     * `content/articles/`: Markdown files and bundles, no page of its own.
     *
     * @return list<PageFile|PageBundle>
     */
    private function scanArticles(DirEntry $directory, Warnings $warnings): array
    {
        $entries = $this->lister->list($directory->absolute, $directory->path);
        if ($this->hasFile($entries, self::PAGE)) {
            throw new ContentException(
                'content/articles/ cannot be a page bundle; articles are the .md files and bundles inside it.',
                $directory->path.'/'.self::PAGE,
            );
        }

        $items = [];
        foreach ($this->scanContainer($entries, [self::ARTICLES], true, $warnings) as $child) {
            if ($child instanceof PageFile || $child instanceof PageBundle) {
                $items[] = $child;
            }
        }

        return $items;
    }

    /**
     * @param  list<string>  $parentSegments
     */
    private function scanDirectory(DirEntry $directory, array $parentSegments, bool $inArticles, Warnings $warnings): PageBundle|PlainDir
    {
        $this->assertRouteName($directory->nfc, $directory->path, 'folder name');
        $segments = [...$parentSegments, $directory->nfc];

        $entries = $this->lister->list($directory->absolute, $directory->path);
        $hasPage = $this->hasFile($entries, self::PAGE);

        if ($inArticles && ! $hasPage) {
            throw new ContentException(
                'subfolders of the articles folder must be page bundles, but this folder has no page.md. '
                .'Add a page.md (the article), or move the folder out of content/articles/.',
                $directory->path,
            );
        }

        if ($hasPage) {
            return $this->scanBundle($directory, $entries, $segments, $warnings);
        }

        return new PlainDir(
            $directory->path,
            $segments,
            $this->scanContainer($entries, $segments, false, $warnings),
        );
    }

    /**
     * @param  list<DirEntry>  $entries
     * @param  list<string>  $segments
     */
    private function scanBundle(DirEntry $directory, array $entries, array $segments, Warnings $warnings): PageBundle
    {
        $attachments = $this->collectAttachments($entries, '', $directory->path, $warnings);
        usort($attachments, static fn (Attachment $a, Attachment $b): int => strcmp($a->relativePath, $b->relativePath));

        return new PageBundle($directory->path, $directory->path.'/'.self::PAGE, $segments, $attachments);
    }

    /**
     * @param  list<DirEntry>  $entries
     * @return list<Attachment>
     */
    private function collectAttachments(array $entries, string $relative, string $bundlePath, Warnings $warnings): array
    {
        $this->assertNoReservedNames($entries);

        $attachments = [];
        foreach ($entries as $entry) {
            $relativePath = $relative === '' ? $entry->nfc : $relative.'/'.$entry->nfc;

            if ($entry->isDirectory) {
                $this->assertRouteName($entry->nfc, $entry->path, 'folder name');
                $children = $this->lister->list($entry->absolute, $entry->path);
                array_push($attachments, ...$this->collectAttachments($children, $relativePath, $bundlePath, $warnings));

                continue;
            }

            if ($relative === '' && $entry->name === self::PAGE) {
                continue;
            }
            if (str_ends_with($entry->nfc, '.md')) {
                throw new ContentException(
                    'additional Markdown files are not allowed inside a page bundle: Markdown is never an attachment '
                    .'and never an implicit page. Move it out of '.$bundlePath.'/ (e.g. next to the bundle as its own page), '
                    .'or rename the extension if it is not Markdown.',
                    $entry->path,
                );
            }
            if (str_ends_with($entry->nfc, '.yaml') || str_ends_with($entry->nfc, '.yml')) {
                $warnings->add($entry->path.': YAML files in a page bundle are ignored (they are never attachments).');

                continue;
            }

            $this->assertRouteName($entry->nfc, $entry->path, 'attachment name');
            $attachments[] = new Attachment($entry->path, $relativePath);
        }

        return $attachments;
    }

    /**
     * @param  list<DirEntry>  $entries
     */
    private function assertNoReservedNames(array $entries): void
    {
        foreach ($entries as $entry) {
            if (! $entry->isDirectory && ($entry->nfc === 'index.md' || $entry->nfc === '_index.md')) {
                throw new ContentException(
                    $entry->name.' is a reserved name. Use page.md for a page bundle.',
                    $entry->path,
                );
            }
        }
    }

    /**
     * `name.md` next to `name/page.md` defines the same route twice.
     *
     * @param  list<DirEntry>  $entries
     * @param  array<string, PageFile>  $pages  by stem
     */
    private function assertNoSideBySide(array $entries, array $pages): void
    {
        foreach ($entries as $entry) {
            if (! $entry->isDirectory || ! isset($pages[$entry->nfc])) {
                continue;
            }
            if (is_file($entry->absolute.'/'.self::PAGE)) {
                throw new ContentException(
                    $pages[$entry->nfc]->path.' and '.$entry->path.'/'.self::PAGE.' both define the route of "'.$entry->nfc.'". '
                    .'Keep one of them.',
                    $pages[$entry->nfc]->path,
                );
            }
        }
    }

    /**
     * @param  list<DirEntry>  $entries
     */
    private function hasFile(array $entries, string $name): bool
    {
        foreach ($entries as $entry) {
            if (! $entry->isDirectory && $entry->name === $name) {
                return true;
            }
        }

        return false;
    }

    private function assertRouteName(string $name, string $path, string $what): void
    {
        $violation = NameRules::violation($name);
        if ($violation !== null) {
            throw new ContentException(
                'the '.$what.' "'.$name.'" cannot be used in a URL because '.$violation.'. Rename it.',
                $path,
            );
        }
    }
}
