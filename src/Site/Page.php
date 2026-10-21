<?php

declare(strict_types=1);

namespace Astro\Verse\Site;

use Astro\Verse\Content\Attachment;
use Astro\Verse\Content\FrontMatter;

final readonly class Page
{
    /**
     * @param  string  $sourcePath  site-relative path of the Markdown file
     * @param  ?string  $bundleDirectory  site-relative bundle directory, null for a page file
     * @param  ?string  $route  null for external articles (no local page)
     * @param  list<Attachment>  $attachments
     * @param  string  $body  raw Markdown (without front matter)
     */
    public function __construct(
        public string $sourcePath,
        public ?string $bundleDirectory,
        public FrontMatter $meta,
        public PageRole $role,
        public ?string $route,
        public array $attachments,
        public string $body,
        public PublicationState $state,
    ) {}

    public function isExternal(): bool
    {
        return $this->meta->isExternal();
    }

    /**
     * Draft or scheduled page that is only present in a drafts preview.
     */
    public function isUnpublished(): bool
    {
        return $this->state !== PublicationState::Published;
    }

    /**
     * Tie-break key: the resolved route, or the external URL for external items.
     */
    public function tieKey(): string
    {
        return $this->route ?? $this->meta->externalUrl ?? $this->sourcePath;
    }
}
