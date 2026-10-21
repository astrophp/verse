<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Support;

use Astro\Verse\Content\FrontMatter;
use Astro\Verse\Site\Page;
use Astro\Verse\Site\PageRole;
use Astro\Verse\Site\PublicationState;
use DateTimeImmutable;
use DateTimeZone;

final class PageFactory
{
    /**
     * @param  list<string>  $tags
     */
    public static function article(
        string $route,
        string $title = 'Title',
        ?string $date = '2026-01-01',
        ?string $updated = null,
        ?int $order = null,
        array $tags = [],
        ?string $externalUrl = null,
    ): Page {
        $zone = new DateTimeZone('UTC');

        return new Page(
            sourcePath: 'content'.rtrim($route, '/').'.md',
            bundleDirectory: null,
            meta: new FrontMatter(
                title: $title,
                date: $date === null ? null : new DateTimeImmutable($date, $zone),
                updated: $updated === null ? null : new DateTimeImmutable($updated, $zone),
                tags: $tags,
                externalUrl: $externalUrl,
                order: $order,
            ),
            role: PageRole::Article,
            route: $externalUrl === null ? $route : null,
            attachments: [],
            body: '',
            state: PublicationState::Published,
        );
    }
}
