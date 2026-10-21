<?php

declare(strict_types=1);

namespace Astro\Verse\Site;

use Astro\Verse\Error\ContentException;
use Astro\Verse\Routing\RouteResolver;
use Astro\Verse\Support\Slug;
use Astro\Verse\Support\Unicode;

final class ArticlesBuilder
{
    /**
     * @param  list<Page>  $eligibleItems  already filtered by eligibility, any order
     */
    public static function build(array $eligibleItems): Articles
    {
        $items = ItemSorter::newestFirst($eligibleItems);

        return new Articles($items, Listing::paginate(Articles::ROUTE, $items, Articles::PER_PAGE), self::tags($items));
    }

    /**
     * @param  list<Page>  $items  newest first
     * @return list<Tag>
     */
    private static function tags(array $items): array
    {
        /** @var array<string, string> $labels label by slug */
        $labels = [];
        /** @var array<string, list<Page>> $bySlug */
        $bySlug = [];

        foreach ($items as $item) {
            foreach ($item->meta->tags as $label) {
                $slug = Slug::make($label);
                if (isset($labels[$slug]) && $labels[$slug] !== $label) {
                    throw new ContentException(
                        'the tags "'.$labels[$slug].'" and "'.$label.'" both produce the URL segment "'.$slug.'" '
                        .'and would be merged. Rename one of them so they differ.',
                        $item->sourcePath,
                        'tags',
                        RouteResolver::tagIndexRoute(),
                    );
                }
                $labels[$slug] = $label;
                $bySlug[$slug][] = $item;
            }
        }

        $tags = [];
        foreach ($bySlug as $slug => $tagged) {
            $tagRoute = RouteResolver::tagRoute((string) $slug);
            $tags[] = new Tag(
                $labels[$slug],
                (string) $slug,
                $tagRoute,
                Listing::paginate($tagRoute, $tagged, Articles::PER_PAGE),
            );
        }

        usort(
            $tags,
            static fn (Tag $a, Tag $b): int => strcmp(Unicode::sortKey($a->label), Unicode::sortKey($b->label)) ?: strcmp($a->label, $b->label),
        );

        return $tags;
    }
}
