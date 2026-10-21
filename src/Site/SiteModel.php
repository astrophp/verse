<?php

declare(strict_types=1);

namespace Astro\Verse\Site;

use Astro\Verse\Build\BuildContext;
use Astro\Verse\Content\PublicFile;
use Astro\Verse\Routing\OutputRegistry;
use Astro\Verse\Routing\UrlGenerator;

/**
 * The eligible content of one build, ready for rendering (no rendering happens here).
 */
final readonly class SiteModel
{
    /**
     * @param  list<Page>  $pages  every eligible page: standalone pages and articles (external ones included)
     * @param  list<PublicFile>  $publicFiles
     * @param  list<string>  $excludedSources  site-relative Markdown sources dropped as drafts or scheduled pages in this build
     */
    public function __construct(
        public BuildContext $context,
        public array $pages,
        public Articles $articles,
        public Menu $menu,
        public array $publicFiles,
        public OutputRegistry $registry,
        public UrlGenerator $urls,
        public array $excludedSources = [],
    ) {}

    public function pageBySource(string $sourcePath): ?Page
    {
        foreach ($this->pages as $page) {
            if ($page->sourcePath === $sourcePath) {
                return $page;
            }
        }

        return null;
    }
}
