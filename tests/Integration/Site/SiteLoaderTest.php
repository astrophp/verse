<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Site;

use Astro\Verse\Build\BuildContext;
use Astro\Verse\Build\BuildMode;
use Astro\Verse\Config\ConfigLoader;
use Astro\Verse\Error\ContentException;
use Astro\Verse\Site\Articles;
use Astro\Verse\Site\Page;
use Astro\Verse\Site\PageRole;
use Astro\Verse\Site\PublicationState;
use Astro\Verse\Site\SiteModel;
use Astro\Verse\Support\Warnings;
use Astro\Verse\Tests\Support\TestCase;
use Astro\Verse\Verse;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class SiteLoaderTest extends TestCase
{
    /**
     * A site with every kind of source, loaded at 2026-10-20T12:00:00Z.
     *
     * @return array<string, string>
     */
    private static function notes(): array
    {
        return [
            'content/about.md' => self::markdown('About', ['menu' => 'true', 'order' => '3']),
            'content/projects.md' => self::markdown('Projects', ['menu' => 'true']),
            'content/legal/privacy.md' => self::markdown('Privacy'),
            'content/articles/a.md' => self::markdown('A', ['date' => '2026-03-01', 'tags' => '[PHP, Laravel]']),
            'content/articles/b/page.md' => self::markdown('B', ['date' => '2026-02-01', 'tags' => '[PHP]']),
            'content/articles/b/diagram.png' => 'png',
            'content/articles/c.md' => self::markdown('C', ['date' => '2026-01-15', 'external_url' => 'https://medium.com/@x/c', 'tags' => '[Medium]']),
            'content/articles/draft.md' => self::markdown('Draft', ['date' => '2026-04-01', 'draft' => 'true', 'tags' => '[Secret]']),
            'content/articles/future.md' => self::markdown('Future', ['date' => '2027-01-01', 'tags' => '[Soon]']),
            'public/robots.txt' => 'User-agent: *',
        ];
    }

    /**
     * @return list<string>
     */
    private static function titles(Articles $articles): array
    {
        return array_map(static fn (Page $page) => $page->meta->title, $articles->items);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function assertLoadError(array $files, string $field, string ...$messageParts): void
    {
        try {
            $this->loadSite($files);
        } catch (ContentException $exception) {
            $this->assertStringContainsString($field, (string) $exception->field.'|'.$exception->getMessage(), $exception->getMessage());
            foreach ($messageParts as $part) {
                $this->assertStringContainsString($part, $exception->getMessage());
            }

            return;
        }
        $this->fail('Expected a ContentException');
    }

    // --- routes ------------------------------------------------------------------------------------

    #[Test]
    public function every_source_gets_its_fr_rte_001_route(): void
    {
        $site = $this->loadSite(self::notes());
        $owners = $site->registry->owners();

        foreach ([
            'index.html',                       // the home page
            'articles/index.html',              // the article archive
            'about/index.html',                 // content/about.md
            'legal/privacy/index.html',         // plain folder
            'articles/a/index.html',            // article file
            'articles/b/index.html',            // article bundle
            'articles/b/diagram.png',           // attachment
            'tags/index.html',                  // tag index
            'tags/php/index.html',              // tag archive
            'tags/laravel/index.html',
            'robots.txt',                       // public file
            '404.html',
            '.verse-output',
        ] as $path) {
            $this->assertArrayHasKey($path, $owners, $path);
        }

        // the home page has no pagination
        $this->assertArrayNotHasKey('page/2/index.html', $owners);

        $routes = array_map(static fn (Page $page) => $page->route, $site->pages);
        $this->assertContains('/about/', $routes);
        $this->assertContains('/legal/privacy/', $routes);
        $this->assertContains('/articles/a/', $routes);
        $this->assertContains('/articles/b/', $routes);
    }

    #[Test]
    public function a_bundle_and_a_file_give_the_same_route_shape(): void
    {
        $site = $this->loadSite(['content/about/page.md' => self::markdown('About'), 'content/other.md' => self::markdown('Other')]);

        $this->assertSame(['/about/', '/other/'], array_map(static fn (Page $page) => $page->route, $site->pages));
        $this->assertSame('content/about', $site->pageBySource('content/about/page.md')?->bundleDirectory);
    }

    #[Test]
    public function slug_changes_only_the_final_segment(): void
    {
        $site = $this->loadSite([
            'content/articles/long-file-name.md' => self::markdown('X', ['date' => '2026-01-01', 'slug' => 'short']),
            'content/legal/terms.md' => self::markdown('Terms', ['slug' => 'tos']),
        ]);

        $this->assertSame('/articles/short/', $site->pageBySource('content/articles/long-file-name.md')?->route);
        $this->assertSame('/legal/tos/', $site->pageBySource('content/legal/terms.md')?->route);
    }

    #[Test]
    public function permalink_replaces_the_whole_route_and_attachments_follow_it(): void
    {
        $site = $this->loadSite([
            'content/articles/post/page.md' => self::markdown('X', ['date' => '2026-01-01', 'permalink' => '/notes/custom/', 'slug' => 'ignored']),
            'content/articles/post/img/a.png' => 'x',
        ]);

        $this->assertSame('/notes/custom/', $site->pageBySource('content/articles/post/page.md')?->route);
        $owners = $site->registry->owners();
        $this->assertArrayHasKey('notes/custom/index.html', $owners);
        $this->assertArrayHasKey('notes/custom/img/a.png', $owners);
        $this->assertArrayNotHasKey('articles/post/index.html', $owners);
    }

    #[Test]
    public function unicode_and_spaces_follow_the_one_url_contract(): void
    {
        $site = $this->loadSite([
            'content/çalışma.md' => self::markdown('Work'),
            'content/my page.md' => self::markdown('Spaced'),
            'content/articles/çalışma notları/page.md' => self::markdown('N', ['date' => '2026-01-01']),
            'content/articles/çalışma notları/my diagram.png' => 'x',
        ]);

        $this->assertSame('/çalışma/', $site->pageBySource('content/çalışma.md')?->route);
        $this->assertSame('/%C3%A7al%C4%B1%C5%9Fma/', $site->urls->page('/çalışma/'));
        $this->assertSame('/my%20page/', $site->urls->page((string) $site->pageBySource('content/my page.md')?->route));
        $this->assertArrayHasKey('çalışma/index.html', $site->registry->owners());
        $this->assertArrayHasKey('articles/çalışma notları/my diagram.png', $site->registry->owners());
        $this->assertSame(
            '/articles/%C3%A7al%C4%B1%C5%9Fma%20notlar%C4%B1/my%20diagram.png',
            $site->urls->asset('articles/çalışma notları/my diagram.png'),
        );
    }

    #[Test]
    public function the_context_base_path_reaches_the_url_generator(): void
    {
        $root = $this->makeSite(['verse.yaml' => "title: x\nurl: https://example.test/notes\n", 'content/about.md' => self::markdown('About')]);
        $site = $this->loadSite([], root: $root);

        $this->assertSame('/notes/', $site->context->basePath);
        $this->assertSame('/notes/about/', $site->urls->page('/about/'));
        $this->assertSame('https://example.test/notes/about/', $site->urls->canonical('/about/'));
    }

    // --- eligibility ------------------------------------------------------------------------------

    #[Test]
    public function drafts_and_future_items_are_dropped_before_anything_is_assembled(): void
    {
        $site = $this->loadSite(self::notes());

        $this->assertSame(['A', 'B', 'C'], self::titles($site->articles));
        $this->assertNull($site->pageBySource('content/articles/draft.md'));
        $this->assertNull($site->pageBySource('content/articles/future.md'));

        $owners = $site->registry->owners();
        $this->assertArrayNotHasKey('articles/draft/index.html', $owners);
        $this->assertArrayNotHasKey('articles/future/index.html', $owners);
        $this->assertArrayNotHasKey('tags/secret/index.html', $owners);
        $this->assertArrayNotHasKey('tags/soon/index.html', $owners);

        $this->assertSame(['laravel', 'medium', 'php'], array_map(static fn ($tag) => $tag->slug, $site->articles->tags));
        $this->assertCount(3, $site->articles->listing->items);
        foreach ($site->pages as $page) {
            $this->assertFalse($page->isUnpublished());
            $this->assertSame(PublicationState::Published, $page->state);
        }
    }

    #[Test]
    public function drafts_mode_keeps_and_flags_unpublished_items(): void
    {
        $site = $this->loadSite(self::notes(), drafts: true);

        // newest first: Future (2027), Draft (April), A, B, C
        $this->assertSame(['Future', 'Draft', 'A', 'B', 'C'], self::titles($site->articles));
        $draft = $site->pageBySource('content/articles/draft.md');
        $future = $site->pageBySource('content/articles/future.md');
        $published = $site->pageBySource('content/articles/a.md');
        $this->assertNotNull($draft);
        $this->assertNotNull($future);
        $this->assertNotNull($published);
        $this->assertSame(PublicationState::Draft, $draft->state);
        $this->assertSame(PublicationState::Scheduled, $future->state);
        $this->assertTrue($draft->isUnpublished());
        $this->assertTrue($future->isUnpublished());
        $this->assertFalse($published->isUnpublished());
        $this->assertArrayHasKey('articles/draft/index.html', $site->registry->owners());
        $this->assertArrayHasKey('tags/secret/index.html', $site->registry->owners());
        $this->assertCount(5, $site->articles->listing->items);
    }

    #[Test]
    public function a_draft_that_is_also_in_the_future_is_a_draft(): void
    {
        $site = $this->loadSite([
            'content/articles/x.md' => self::markdown('X', ['date' => '2027-01-01', 'draft' => 'true']),
        ], drafts: true);

        $this->assertSame(PublicationState::Draft, $site->pageBySource('content/articles/x.md')?->state);
    }

    #[Test]
    public function an_item_dated_exactly_now_is_published(): void
    {
        $files = [
            'content/articles/x.md' => self::markdown('X', ['date' => '2026-10-20T12:00:00Z']),
        ];

        $at = $this->loadSite($files, now: '2026-10-20T12:00:00Z');
        $before = $this->loadSite($files, now: '2026-10-20T11:59:59Z');

        $this->assertNotNull($at->pageBySource('content/articles/x.md'));
        $this->assertNull($before->pageBySource('content/articles/x.md'));
    }

    #[Test]
    public function date_only_values_become_eligible_at_midnight_in_the_site_timezone(): void
    {
        $files = [
            'verse.yaml' => "title: x\ntimezone: Europe/Istanbul\n",
            'content/articles/x.md' => self::markdown('X', ['date' => '2026-10-21']),
        ];

        // 2026-10-21T00:00+03:00 is 2026-10-20T21:00Z
        $this->assertNull($this->loadSite($files, now: '2026-10-20T20:59:59Z')->pageBySource('content/articles/x.md'));
        $this->assertNotNull($this->loadSite($files, now: '2026-10-20T21:00:00Z')->pageBySource('content/articles/x.md'));
    }

    #[Test]
    public function ineligible_pages_leave_menus_and_standalone_lists(): void
    {
        $site = $this->loadSite([
            'content/about.md' => self::markdown('About', ['menu' => 'true', 'draft' => 'true']),
            'content/later.md' => self::markdown('Later', ['menu' => 'true', 'date' => '2030-01-01']),
            'content/visible.md' => self::markdown('Visible', ['menu' => 'true']),
        ]);

        $this->assertSame(['/', '/articles/', '/tags/', '/visible/'], array_map(static fn ($item) => $item->route, $site->menu->items));
        $this->assertSame(['/visible/'], array_map(static fn (Page $page) => $page->route, $site->pages));
        $this->assertArrayNotHasKey('about/index.html', $site->registry->owners());
    }

    #[Test]
    public function a_dropped_page_does_not_collide_with_a_live_one(): void
    {
        $site = $this->loadSite([
            'content/articles/one.md' => self::markdown('One', ['date' => '2026-01-01', 'slug' => 'same']),
            'content/articles/two.md' => self::markdown('Two', ['date' => '2026-01-01', 'slug' => 'same', 'draft' => 'true']),
        ]);

        $this->assertSame('/articles/same/', $site->pageBySource('content/articles/one.md')?->route);
    }

    #[Test]
    public function invalid_front_matter_fails_even_on_a_draft_that_would_be_dropped(): void
    {
        $this->assertLoadError(
            ['content/about.md' => self::markdown('X', ['draft' => 'true', 'date' => '2026-02-30'])],
            'date',
            'content/about.md',
        );
    }

    // --- roles and placement -----------------------------------------------------------------------

    #[Test]
    public function files_in_the_articles_folder_are_articles_and_everything_else_is_a_page(): void
    {
        $site = $this->loadSite([
            'content/about.md' => self::markdown('About'),
            'content/articles/a.md' => self::markdown('A', ['date' => '2026-01-01']),
            'content/articles/b/page.md' => self::markdown('B', ['date' => '2026-01-01']),
            'content/notes/articles/c.md' => self::markdown('C'),
        ]);

        $roles = [];
        foreach ($site->pages as $page) {
            $roles[$page->sourcePath] = $page->role;
        }
        $this->assertSame(PageRole::Page, $roles['content/about.md']);
        $this->assertSame(PageRole::Article, $roles['content/articles/a.md']);
        $this->assertSame(PageRole::Article, $roles['content/articles/b/page.md']);
        // a deeper folder named "articles" is an ordinary plain folder: pages need no date
        $this->assertSame(PageRole::Page, $roles['content/notes/articles/c.md']);
        $this->assertSame('/notes/articles/c/', $site->pageBySource('content/notes/articles/c.md')?->route);
        $this->assertSame(['A', 'B'], array_map(static fn (Page $page) => $page->meta->title, $site->articles->items));
    }

    #[Test]
    public function a_type_key_is_an_unknown_front_matter_key(): void
    {
        $this->assertLoadError(['content/a.md' => self::markdown('A', ['type' => 'page'])], 'type', 'unknown key "type"', 'content/a.md');
        $this->assertLoadError(
            ['content/articles/a.md' => self::markdown('A', ['type' => 'article', 'date' => '2026-01-01'])],
            'type',
            'unknown key "type"',
        );
    }

    #[Test]
    public function external_articles_are_only_valid_in_the_articles_folder(): void
    {
        $this->assertLoadError(
            ['content/a.md' => self::markdown('A', ['external_url' => 'https://medium.com/a'])],
            'external_url',
            'only valid for articles',
            'content/articles/',
        );
        $this->assertLoadError(
            ['content/notes/articles/a.md' => self::markdown('A', ['external_url' => 'https://medium.com/a'])],
            'external_url',
            'only valid for articles',
        );
        $this->assertLoadError(
            ['content/articles/a/page.md' => self::markdown('A', ['external_url' => 'https://medium.com/a', 'date' => '2026-01-01'])],
            'external_url',
            'external articles have no page',
            'name.md',
        );
        $this->assertLoadError(
            ['content/articles/a.md' => self::markdown('A', ['external_url' => 'https://medium.com/a', 'date' => '2026-01-01', 'menu' => 'true'])],
            'menu',
            'cannot be in the main menu',
        );
    }

    #[Test]
    public function articles_require_a_date(): void
    {
        $this->assertLoadError(
            ['content/articles/a.md' => self::markdown('A')],
            'date',
            'content/articles/a.md',
            'date: YYYY-MM-DD',
        );
        $this->assertLoadError(['content/articles/a/page.md' => self::markdown('A')], 'date', 'content/articles/a/page.md');
        $this->assertLoadError(
            ['content/articles/a.md' => self::markdown('A', ['external_url' => 'https://medium.com/a'])],
            'date',
        );

        $site = $this->loadSite(['content/about.md' => self::markdown('About')]);
        $this->assertNull($site->pages[0]->meta->date);
    }

    #[Test]
    public function content_page_md_is_not_a_page(): void
    {
        $this->assertLoadError(['content/page.md' => self::markdown('Home')], 'content/page.md', 'the home page is generated from content/articles/');
    }

    // --- collisions --------------------------------------------------------------------------------

    #[Test]
    public function duplicate_routes_name_both_owners(): void
    {
        $this->assertLoadError(
            [
                'content/articles/a.md' => self::markdown('A', ['date' => '2026-01-01', 'slug' => 'same']),
                'content/articles/b.md' => self::markdown('B', ['date' => '2026-01-01', 'slug' => 'same']),
            ],
            'conflict',
            'articles/same/index.html',
            'page content/articles/a.md',
            'page content/articles/b.md',
        );
    }

    #[Test]
    public function a_permalink_can_collide_with_another_page(): void
    {
        $this->assertLoadError(
            [
                'content/about.md' => self::markdown('About'),
                'content/other.md' => self::markdown('Other', ['permalink' => '/about/']),
            ],
            'conflict',
            'page content/about.md',
            'page content/other.md',
        );
    }

    #[Test]
    public function case_only_differences_are_collisions_naming_both_owners(): void
    {
        $this->assertLoadError(
            [
                'content/a.md' => self::markdown('A', ['slug' => 'Foo']),
                'content/b.md' => self::markdown('B', ['slug' => 'foo']),
            ],
            'conflict',
            'letter case',
            'Foo/index.html',
            'foo/index.html',
            'page content/a.md',
            'page content/b.md',
        );
    }

    #[Test]
    public function a_public_file_that_is_also_a_page_directory_is_a_file_versus_directory_collision(): void
    {
        $this->assertLoadError(
            [
                'content/about.md' => self::markdown('About'),
                'public/about' => 'a file called about',
            ],
            'conflict',
            'public file public/about',
            'page content/about.md',
            'directory',
        );
    }

    #[Test]
    public function public_files_collide_with_generated_output_naming_both_owners(): void
    {
        $this->assertLoadError(
            ['public/index.html' => '<h1>x</h1>'],
            'conflict',
            'index.html',
            'the home page',
            'public file public/index.html',
        );
        $this->assertLoadError(
            ['public/404.html' => 'x'],
            'conflict',
            '404.html',
            'the 404 page',
            'public file public/404.html',
        );
        $this->assertLoadError(
            ['content/about.md' => self::markdown('About'), 'public/about/index.html' => 'x'],
            'conflict',
            'about/index.html',
            'page content/about.md',
            'public file public/about/index.html',
        );
        $this->assertLoadError(['public/tags/index.html' => 'x'], 'conflict', 'tags/index.html', 'the tag index', 'public file public/tags/index.html');
    }

    #[Test]
    public function an_attachment_can_collide_with_a_public_file(): void
    {
        $this->assertLoadError(
            [
                'content/post/page.md' => self::markdown('Post'),
                'content/post/a.png' => 'x',
                'public/post/a.png' => 'y',
            ],
            'conflict',
            'attachment content/post/a.png',
            'public file public/post/a.png',
        );
    }

    #[Test]
    public function the_theme_asset_area_is_reserved(): void
    {
        $this->assertLoadError(
            ['public/assets/theme/app.css' => 'x'],
            'conflict',
            'assets/theme/',
            'public file public/assets/theme/app.css',
        );
        $this->assertLoadError(
            ['content/assets/theme/page.md' => self::markdown('X')],
            'conflict',
            'assets/theme/',
            'content/assets/theme/page.md',
        );
    }

    #[Test]
    public function the_preview_area_is_reserved(): void
    {
        $this->assertLoadError(['public/__verse/status' => 'x'], 'conflict', '__verse/', 'public file public/__verse/status');
        $this->assertLoadError(['content/__verse/page.md' => self::markdown('X')], 'conflict', '__verse/', 'content/__verse/page.md');
    }

    #[Test]
    public function pages_can_collide_with_the_tag_index_and_the_tag_archives(): void
    {
        $articles = [
            'content/articles/a.md' => self::markdown('A', ['date' => '2026-01-02', 'tags' => '[x]']),
        ];

        $this->assertLoadError(
            $articles + ['content/tags.md' => self::markdown('T')],
            'conflict',
            'tags/index.html',
            'page content/tags.md',
            'the tag index',
        );
        $this->assertLoadError(
            $articles + ['content/other.md' => self::markdown('O', ['permalink' => '/tags/x/'])],
            'conflict',
            'tags/x/index.html',
            'page content/other.md',
            'tag archive "x" (page 1)',
        );
        // the tag index and its page do not need any article to exist
        $this->assertLoadError(['content/tags/page.md' => self::markdown('T')], 'conflict', 'tags/index.html', 'content/tags/page.md');
    }

    #[Test]
    public function the_archive_pages_are_claimed(): void
    {
        $files = [];
        for ($i = 1; $i <= 11; $i++) {
            $files[sprintf('content/articles/a%02d.md', $i)] = self::markdown('A'.$i, ['date' => sprintf('2026-01-%02d', $i)]);
        }

        $this->assertLoadError(
            $files + ['content/other.md' => self::markdown('X', ['permalink' => '/articles/page/2/'])],
            'conflict',
            'articles/page/2/index.html',
            'listing page 2 of the articles',
            'content/other.md',
        );
        $this->assertLoadError(
            ['content/articles.md' => self::markdown('Articles')],
            'conflict',
            'articles/index.html',
            'listing page 1 of the articles',
            'page content/articles.md',
        );
        // the home page is not paginated, so /page/ is free for pages
        $this->assertSame('/page/2/', $this->loadSite($files + ['content/page/2.md' => self::markdown('X')])->pageBySource('content/page/2.md')?->route);
    }

    // --- articles, sorting, pagination ---------------------------------------------------------------

    #[Test]
    public function articles_are_sorted_newest_first(): void
    {
        $site = $this->loadSite([
            'content/articles/b.md' => self::markdown('banana', ['date' => '2026-03-01']),
            'content/articles/a.md' => self::markdown('Zebra', ['date' => '2026-01-01']),
            'content/articles/c.md' => self::markdown('Apple', ['date' => '2026-02-01']),
        ]);

        $this->assertSame(['banana', 'Apple', 'Zebra'], self::titles($site->articles));
    }

    #[Test]
    public function the_archive_paginates_ten_per_page_and_the_home_page_does_not(): void
    {
        $files = [];
        for ($i = 1; $i <= 23; $i++) {
            $files[sprintf('content/articles/a%02d.md', $i)] = self::markdown('A'.$i, ['date' => sprintf('2026-01-%02d', $i)]);
        }
        $site = $this->loadSite($files);
        $listing = $site->articles->listing;

        $this->assertSame(3, $listing->pageCount());
        $this->assertSame(23, $listing->itemCount());
        $this->assertSame(['/articles/', '/articles/page/2/', '/articles/page/3/'], array_map(static fn ($page) => $page->route, $listing->pages));
        $this->assertSame('/articles/page/2/', $listing->pages[0]->nextRoute);
        $this->assertSame('/articles/page/2/', $listing->pages[2]->previousRoute);
        $this->assertCount(10, $listing->pages[0]->items);
        $this->assertSame(['A3', 'A2', 'A1'], array_map(static fn (Page $page) => $page->meta->title, $listing->pages[2]->items));
        $this->assertArrayHasKey('articles/page/3/index.html', $site->registry->owners());
        $this->assertArrayNotHasKey('articles/page/4/index.html', $site->registry->owners());
        $this->assertArrayNotHasKey('page/2/index.html', $site->registry->owners());

        $this->assertSame(['A23', 'A22', 'A21', 'A20', 'A19'], array_map(static fn (Page $page) => $page->meta->title, $site->articles->latest()));
    }

    #[Test]
    public function the_archive_is_one_empty_page_without_articles(): void
    {
        foreach ([[], ['content/articles/.keep' => '']] as $files) {
            $site = $this->loadSite($files);

            $this->assertSame(1, $site->articles->listing->pageCount());
            $this->assertSame([], $site->articles->listing->pages[0]->items);
            $this->assertNull($site->articles->listing->pages[0]->nextRoute);
            $this->assertSame([], $site->articles->items);
            $this->assertSame([], $site->articles->latest());
            $this->assertSame([], $site->articles->tags);
            $owners = $site->registry->owners();
            $this->assertSame('the home page', $site->registry->owner('index.html'));
            $this->assertArrayHasKey('articles/index.html', $owners);
            $this->assertArrayNotHasKey('articles/page/2/index.html', $owners);
            $this->assertArrayHasKey('tags/index.html', $owners);
        }
    }

    // --- tags ---------------------------------------------------------------------------------------

    #[Test]
    public function tag_index_counts_include_external_items_and_are_sorted_by_label(): void
    {
        $site = $this->loadSite([
            'content/articles/a.md' => self::markdown('A', ['date' => '2026-01-04', 'tags' => '[banana, Apple]']),
            'content/articles/b.md' => self::markdown('B', ['date' => '2026-01-03', 'tags' => '[Apple, cherry]']),
            'content/articles/c.md' => self::markdown('C', ['date' => '2026-01-02', 'tags' => '[Apple]', 'external_url' => 'https://medium.com/c']),
            'content/articles/d.md' => self::markdown('D', ['date' => '2026-01-01', 'tags' => '[Çay]']),
        ]);
        $tags = $site->articles->tags;

        $this->assertSame(['Apple', 'banana', 'cherry', 'Çay'], array_map(static fn ($t) => $t->label, $tags));
        $this->assertSame([3, 1, 1, 1], array_map(static fn ($t) => $t->count(), $tags));
        $this->assertSame(['apple', 'banana', 'cherry', 'çay'], array_map(static fn ($t) => $t->slug, $tags));
        $this->assertSame('/tags/apple/', $tags[0]->route);
        $this->assertSame(['A', 'B', 'C'], array_map(static fn (Page $p) => $p->meta->title, $tags[0]->listing->items));
    }

    #[Test]
    public function tag_archives_paginate_like_the_articles_archive(): void
    {
        $files = [];
        for ($i = 1; $i <= 12; $i++) {
            $files[sprintf('content/articles/a%02d.md', $i)] = self::markdown('A'.$i, ['date' => sprintf('2026-01-%02d', $i), 'tags' => '[php]']);
        }
        $site = $this->loadSite($files);

        $this->assertArrayHasKey('tags/php/index.html', $site->registry->owners());
        $this->assertArrayHasKey('tags/php/page/2/index.html', $site->registry->owners());
        $this->assertArrayNotHasKey('tags/php/page/3/index.html', $site->registry->owners());
        $this->assertSame(2, $site->articles->tags[0]->listing->pageCount());
        $this->assertSame('/tags/php/page/2/', $site->articles->tags[0]->listing->pages[0]->nextRoute);
    }

    #[Test]
    public function tags_that_share_a_slug_are_an_error_naming_both_labels(): void
    {
        $this->assertLoadError(
            [
                'content/articles/a.md' => self::markdown('A', ['date' => '2026-01-02', 'tags' => '[PHP]']),
                'content/articles/b.md' => self::markdown('B', ['date' => '2026-01-01', 'tags' => '[php]']),
            ],
            'tags',
            '"PHP"',
            '"php"',
            'php',
            'content/articles/b.md',
        );
        $this->assertLoadError(
            ['content/articles/a.md' => self::markdown('A', ['date' => '2026-01-01', 'tags' => '["C#", "C++"]'])],
            'tags',
            '"C#"',
            '"C++"',
        );
    }

    #[Test]
    public function colliding_tags_are_fine_when_the_other_article_is_a_draft(): void
    {
        $site = $this->loadSite([
            'content/articles/a.md' => self::markdown('A', ['date' => '2026-01-02', 'tags' => '[PHP]']),
            'content/articles/b.md' => self::markdown('B', ['date' => '2026-01-01', 'tags' => '[php]', 'draft' => 'true']),
        ]);

        $this->assertCount(1, $site->articles->tags);
    }

    // --- menu ------------------------------------------------------------------------------------------

    #[Test]
    public function the_menu_has_home_articles_and_tags_first_then_pages_by_order_and_title(): void
    {
        $site = $this->loadSite(self::notes());

        $this->assertSame(
            ['/', '/articles/', '/tags/', '/about/', '/projects/'],
            array_map(static fn ($item) => $item->route, $site->menu->items),
        );
        $this->assertSame(['Home', 'Articles', 'Tags', 'About', 'Projects'], array_map(static fn ($item) => $item->label, $site->menu->items));
        $this->assertSame('/', $site->menu->current('/')?->route);
        $this->assertSame('/articles/', $site->menu->current('/articles/')?->route);
        $this->assertSame('/articles/', $site->menu->current('/articles/page/2/')?->route);
        $this->assertSame('/articles/', $site->menu->current('/articles/b/')?->route);
        $this->assertSame('/tags/', $site->menu->current('/tags/php/')?->route);
        $this->assertSame('/tags/', $site->menu->current('/tags/')?->route);
        $this->assertSame('/about/', $site->menu->current('/about/')?->route);
        $this->assertNull($site->menu->current('/legal/privacy/'));
    }

    #[Test]
    public function the_fixed_items_come_first_even_when_pages_have_a_lower_order_or_there_are_no_articles(): void
    {
        $site = $this->loadSite(['content/about.md' => self::markdown('About', ['menu' => 'true', 'order' => '-5'])]);

        $this->assertSame(['/', '/articles/', '/tags/', '/about/'], array_map(static fn ($item) => $item->route, $site->menu->items));

        $empty = $this->loadSite([]);
        $this->assertSame(['Home', 'Articles', 'Tags'], array_map(static fn ($item) => $item->label, $empty->menu->items));
    }

    #[Test]
    public function an_article_with_menu_true_adds_no_menu_item(): void
    {
        $site = $this->loadSite(self::notes() + [
            'content/articles/pinned.md' => self::markdown('Pinned', ['date' => '2026-03-15', 'menu' => 'true', 'order' => '1']),
        ]);

        $this->assertNotNull($site->pageBySource('content/articles/pinned.md'));
        $this->assertSame(['Home', 'Articles', 'Tags', 'About', 'Projects'], array_map(static fn ($item) => $item->label, $site->menu->items));
        $this->assertSame(['/', '/articles/', '/tags/', '/about/', '/projects/'], array_map(static fn ($item) => $item->route, $site->menu->items));
    }

    #[Test]
    public function order_without_menu_adds_nothing(): void
    {
        $site = $this->loadSite(['content/about.md' => self::markdown('About', ['order' => '1'])]);

        $this->assertSame(['/', '/articles/', '/tags/'], array_map(static fn ($item) => $item->route, $site->menu->items));
    }

    // --- archive order -------------------------------------------------------------------------------------------

    #[Test]
    public function archive_ties_break_by_route_or_external_url(): void
    {
        $site = $this->loadSite([
            'content/articles/z.md' => self::markdown('Z', ['date' => '2026-01-01']),
            'content/articles/m.md' => self::markdown('M', ['date' => '2026-01-01']),
            'content/articles/x.md' => self::markdown('X', ['date' => '2026-01-01', 'external_url' => 'https://a.test/x']),
        ]);

        $keys = array_map(static fn (Page $page) => $page->tieKey(), $site->articles->listing->items);
        $this->assertSame(['/articles/m/', '/articles/z/', 'https://a.test/x'], $keys);
    }

    // --- external items ------------------------------------------------------------------------------------

    #[Test]
    public function external_items_are_listed_but_have_no_route_or_output(): void
    {
        $site = $this->loadSite(self::notes());
        $external = $site->pageBySource('content/articles/c.md');

        $this->assertNotNull($external);
        $this->assertTrue($external->isExternal());
        $this->assertNull($external->route);
        $this->assertContains($external, $site->articles->items);
        $this->assertContains($external, $site->articles->listing->items);
        $this->assertArrayNotHasKey('articles/c/index.html', $site->registry->owners());
        $this->assertSame(1, $site->articles->tags[1]->count());
    }

    // --- context, warnings ---------------------------------------------------------------------------------

    #[Test]
    public function the_build_context_captures_now_and_derives_the_base_path(): void
    {
        $root = $this->makeSite(['verse.yaml' => "title: x\nurl: https://example.test/notes/\n"]);
        $config = (new ConfigLoader)->load($root, true);
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        $context = BuildContext::create($root.'/', $config, BuildMode::Production, now: $now);

        $this->assertSame($now, $context->now);
        $this->assertSame('/notes/', $context->basePath);
        $this->assertSame($root, $context->root);
        $this->assertSame($root.'/dist', $context->outputDir);
        $this->assertSame(Verse::version(), $context->version);
        $this->assertSame(BuildMode::Production, $context->mode);
        $this->assertFalse($context->drafts);
        $this->assertSame('default', $context->themeName());
        $this->assertSame('/', BuildContext::create($root, (new ConfigLoader)->parseSite('title: x', $root, false))->basePath);
    }

    #[Test]
    public function the_theme_name_reaches_the_build_context(): void
    {
        $root = $this->makeSite([]);
        $themes = $this->themeRegistry(['paper/theme.yaml' => "name: paper\nentries: [app.css]\n"]);
        $config = (new ConfigLoader($themes))->parseSite("title: x\ntheme:\n  name: paper\n", $root, false);

        $this->assertSame('paper', BuildContext::create($root, $config)->themeName());
    }

    #[Test]
    public function drafts_are_only_allowed_in_preview_builds(): void
    {
        $root = $this->makeSite([]);
        $config = (new ConfigLoader)->parseSite('title: x', $root, false);

        $this->expectException(InvalidArgumentException::class);

        BuildContext::create($root, $config, BuildMode::Production, drafts: true);
    }

    #[Test]
    public function scanner_warnings_are_collected_on_the_given_collector(): void
    {
        $warnings = new Warnings;
        $this->loadSite(['content/about/page.md' => self::markdown('About'), 'content/about/data.yaml' => 'a: 1'], warnings: $warnings);

        $this->assertCount(1, $warnings->all());
        $this->assertFalse($warnings->isEmpty());
    }

    #[Test]
    public function the_same_inputs_load_to_the_same_model(): void
    {
        $files = self::notes() + ['verse.yaml' => self::SITE_YAML];
        $root = $this->makeSite($files);

        $first = $this->loadSite([], root: $root);
        $second = $this->loadSite([], root: $root);

        $this->assertEquals($first->registry->owners(), $second->registry->owners());
        $this->assertSame(
            array_map(static fn (Page $page) => $page->sourcePath, $first->pages),
            array_map(static fn (Page $page) => $page->sourcePath, $second->pages),
        );
    }

    #[Test]
    public function page_bodies_exclude_front_matter_and_expose_the_attachments(): void
    {
        $site = $this->loadSite(self::notes());
        $bundle = $site->pageBySource('content/articles/b/page.md') ?? $this->fail('Expected the bundle page');

        $this->assertSame("Body.\n", $bundle->body);
        $this->assertSame(['diagram.png'], array_map(static fn ($a) => $a->relativePath, $bundle->attachments));
    }

    #[Test]
    public function a_site_is_loadable_from_an_unrelated_working_directory(): void
    {
        $cwd = (string) getcwd();
        chdir(sys_get_temp_dir());
        try {
            $site = $this->loadSite(self::notes());
        } finally {
            chdir($cwd);
        }

        $this->assertNotEmpty($site->pages);
    }

    #[Test]
    public function the_site_model_is_a_model(): void
    {
        $site = $this->loadSite(self::notes());

        $this->assertInstanceOf(SiteModel::class, $site);
        $this->assertSame(['robots.txt'], array_map(static fn ($file) => $file->outputPath, $site->publicFiles));
    }
}
