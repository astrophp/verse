<?php

declare(strict_types=1);

namespace Astro\Verse\Site;

use Astro\Verse\Markdown\MarkdownRenderer;
use Astro\Verse\Markdown\RenderedMarkdown;

/**
 * Renders the Markdown of a site's pages with one policy:
 * links are resolved against the site model, relative to the file they are written in.
 */
final readonly class SiteMarkdown
{
    private SiteLinkResolver $links;

    public function __construct(SiteModel $site, private MarkdownRenderer $renderer = new MarkdownRenderer)
    {
        $this->links = new SiteLinkResolver($site);
    }

    public function page(Page $page): RenderedMarkdown
    {
        return $this->renderer->render($page->body, $page->sourcePath, $this->links);
    }
}
