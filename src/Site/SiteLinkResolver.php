<?php

declare(strict_types=1);

namespace Astro\Verse\Site;

use Astro\Verse\Error\ContentException;
use Astro\Verse\Markdown\LinkResolver;
use Astro\Verse\Routing\RouteResolver;
use Astro\Verse\Support\Unicode;

/**
 * Rewrites Markdown links and images against the site model: `.md` sources become their final
 * URL (or the external URL of an external article), attachments of any eligible bundle their output URL, and
 * root-relative paths get the base path. Query and fragment are
 * re-attached unchanged. A link that cannot be honoured is a content error naming the file and the link.
 */
final class SiteLinkResolver implements LinkResolver
{
    /** @var array<string, Page> */
    private array $pages = [];

    /** @var array<string, true> */
    private array $excluded = [];

    /** @var array<string, string> NFC source path of every attachment of an eligible bundle => its decoded output path */
    private array $attachments = [];

    /** @var list<string> NFC bundle directories of pages dropped as not eligible */
    private array $excludedBundles = [];

    private readonly string $basePrefix;

    public function __construct(private readonly SiteModel $site)
    {
        foreach ($site->pages as $page) {
            $this->pages[Unicode::nfc($page->sourcePath)] = $page;
        }
        foreach ($site->pages as $page) {
            if ($page->route === null) {
                continue;
            }
            foreach ($page->attachments as $attachment) {
                $this->attachments[Unicode::nfc($attachment->path)] = RouteResolver::relativeOutputPath(
                    $page->route,
                    Unicode::nfc($attachment->relativePath),
                );
            }
        }
        foreach ($site->excludedSources as $path) {
            $path = Unicode::nfc($path);
            $this->excluded[$path] = true;
            if (basename($path) === 'page.md') {
                $this->excludedBundles[] = dirname($path).'/';
            }
        }
        $this->basePrefix = rtrim($site->urls->page('/'), '/');
    }

    public function resolve(string $sourcePath, string $url, bool $isImage): string
    {
        [$path, $suffix] = self::split($url);

        if ($path === '' || str_starts_with($path, '//') || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $path) === 1) {
            return $url;
        }
        if (str_starts_with($path, '/')) {
            return $this->basePrefix.$path.$suffix;
        }

        $what = $isImage ? 'image' : 'link';
        $target = $this->targetOf($sourcePath, rawurldecode($path), $what, $url);

        if (str_ends_with($target, '.md')) {
            return $this->pageUrl($sourcePath, $target, $what, $url).$suffix;
        }
        $attachment = $this->attachmentUrl($sourcePath, $target, $what, $url);

        return $attachment === null ? $url : $attachment.$suffix;
    }

    /**
     * @return array{string, string} path, and the `?query#fragment` suffix exactly as written
     */
    private static function split(string $url): array
    {
        $cut = strcspn($url, '?#');

        return [substr($url, 0, $cut), substr($url, $cut)];
    }

    /**
     * The site-relative target a relative path points to; `..` may not leave `content/`.
     */
    private function targetOf(string $sourcePath, string $path, string $what, string $url): string
    {
        $segments = explode('/', dirname($sourcePath));
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (count($segments) <= 1) {
                    throw $this->error($sourcePath, $what, $url, 'leaves the content/ directory. Links may only point inside content/ (use a root-relative /path for public files).');
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return Unicode::nfc(implode('/', $segments));
    }

    private function pageUrl(string $sourcePath, string $target, string $what, string $url): string
    {
        $page = $this->pages[$target] ?? null;
        if ($page === null) {
            if (isset($this->excluded[$target])) {
                throw $this->error(
                    $sourcePath,
                    $what,
                    $url,
                    'points to '.$target.', which is a draft or scheduled page and is not part of this build. Publish it, preview with `verse serve`, or remove the link.',
                );
            }

            throw $this->error($sourcePath, $what, $url, 'points to '.$target.', which does not exist. Fix the path or create the file.');
        }

        // External items have no page of their own: the link goes to their destination.
        return $page->route === null
            ? (string) $page->meta->externalUrl
            : $this->site->urls->page($page->route);
    }

    /**
     * Output URL of an attachment of any eligible bundle, or null when the target is not an attachment.
     * An existing file inside the folder of a bundle that is not part of this build is an error.
     */
    private function attachmentUrl(string $sourcePath, string $target, string $what, string $url): ?string
    {
        if (isset($this->attachments[$target])) {
            return $this->site->urls->asset($this->attachments[$target]);
        }

        foreach ($this->excludedBundles as $bundle) {
            if (str_starts_with($target, $bundle) && is_file($this->site->context->root.'/'.$target)) {
                throw $this->error(
                    $sourcePath,
                    $what,
                    $url,
                    'points to '.$target.', an attachment of a draft or scheduled page that is not part of this build. Publish the page, preview with `verse serve`, or remove the link.',
                );
            }
        }

        return null;
    }

    private function error(string $sourcePath, string $what, string $url, string $detail): ContentException
    {
        return new ContentException($what.' "'.$url.'" '.$detail, $sourcePath);
    }
}
