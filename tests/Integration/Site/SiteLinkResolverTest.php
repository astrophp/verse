<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Site;

use Astro\Verse\Error\ContentException;
use Astro\Verse\Site\SiteLinkResolver;
use Astro\Verse\Site\SiteMarkdown;
use Astro\Verse\Site\SiteModel;
use Astro\Verse\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class SiteLinkResolverTest extends TestCase
{
    private const string NOTES_URL = "title: Test Site\nurl: https://example.test/notes/\n";

    /**
     * @return array<string, string>
     */
    private static function site(): array
    {
        return [
            'content/start.md' => self::markdown('Start'),
            'content/about.md' => self::markdown('About'),
            'content/legal/privacy.md' => self::markdown('Privacy', ['slug' => 'privacy-policy']),
            'content/moved.md' => self::markdown('Moved', ['permalink' => '/elsewhere/here/']),
            'content/çalışma notu.md' => self::markdown('Study'),
            'content/articles/a.md' => self::markdown('A', ['date' => '2026-03-01']),
            'content/articles/b/page.md' => self::markdown('B', ['date' => '2026-02-01'], "![fig](diagram.png \"Caption\")\n"),
            'content/articles/b/diagram.png' => 'png',
            'content/articles/b/img/deep photo.png' => 'png',
            'content/articles/b/çizim.png' => 'png',
            'content/articles/c.md' => self::markdown('C', ['date' => '2026-01-15', 'external_url' => 'https://medium.com/@x/c']),
            'content/articles/draft.md' => self::markdown('Draft', ['date' => '2026-04-01', 'draft' => 'true']),
            'content/articles/future.md' => self::markdown('Future', ['date' => '2027-01-01']),
            'public/robots.txt' => 'x',
        ];
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function resolver(array $extra = [], bool $notesBase = false, bool $drafts = false): SiteLinkResolver
    {
        return new SiteLinkResolver($this->model($extra, $notesBase, $drafts));
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function model(array $extra = [], bool $notesBase = false, bool $drafts = false): SiteModel
    {
        $files = $extra + self::site();
        if ($notesBase) {
            $files['verse.yaml'] = self::NOTES_URL;
        }

        return $this->loadSite($files, drafts: $drafts);
    }

    private function assertResolves(string $expected, string $from, string $url, bool $notes = false): void
    {
        $this->assertSame($expected, $this->resolver(notesBase: $notes)->resolve($from, $url, false), $from.' -> '.$url);
    }

    private function assertFails(string $from, string $url, string ...$parts): void
    {
        try {
            $this->resolver()->resolve($from, $url, false);
        } catch (ContentException $exception) {
            $this->assertSame($from, $exception->sourceFile);
            $this->assertStringContainsString('"'.$url.'"', $exception->getMessage());
            foreach ($parts as $part) {
                $this->assertStringContainsString($part, $exception->getMessage());
            }

            return;
        }
        $this->fail('Expected a ContentException for '.$url);
    }

    // --- pages -----------------------------------------------------------------------------------

    #[Test]
    public function markdown_sources_resolve_to_their_routes(): void
    {
        $this->assertResolves('/about/', 'content/start.md', 'about.md');
        $this->assertResolves('/about/', 'content/start.md', './about.md');
        $this->assertResolves('/articles/a/', 'content/start.md', 'articles/a.md');
        $this->assertResolves('/articles/a/', 'content/about.md', 'articles/../articles/a.md');
        $this->assertResolves('/articles/b/', 'content/start.md', 'articles/b/page.md');
        $this->assertResolves('/articles/b/', 'content/articles/a.md', 'b/page.md');
        $this->assertResolves('/articles/a/', 'content/articles/b/page.md', '../a.md');
        $this->assertResolves('/start/', 'content/about.md', 'start.md');
        $this->assertResolves('/about/', 'content/articles/a.md', '../about.md');
    }

    #[Test]
    public function slug_and_permalink_are_honoured(): void
    {
        $this->assertResolves('/legal/privacy-policy/', 'content/start.md', 'legal/privacy.md');
        $this->assertResolves('/elsewhere/here/', 'content/start.md', 'moved.md');
    }

    #[Test]
    public function a_changed_slug_or_permalink_changes_the_link(): void
    {
        $files = [
            'content/legal/privacy.md' => self::markdown('Privacy', ['slug' => 'data-policy']),
            'content/moved.md' => self::markdown('Moved'),
        ];
        $resolver = $this->resolver($files);

        $this->assertSame('/legal/data-policy/', $resolver->resolve('content/start.md', 'legal/privacy.md', false));
        $this->assertSame('/moved/', $resolver->resolve('content/start.md', 'moved.md', false));
    }

    #[Test]
    public function the_base_path_prefixes_page_links(): void
    {
        $this->assertResolves('/notes/about/', 'content/start.md', 'about.md', notes: true);
        $this->assertResolves('/notes/legal/privacy-policy/', 'content/start.md', 'legal/privacy.md', notes: true);
        $this->assertResolves('/notes/start/', 'content/about.md', 'start.md', notes: true);
    }

    #[Test]
    public function query_and_fragment_are_kept_unchanged(): void
    {
        $this->assertResolves('/articles/a/?view=full#details', 'content/start.md', 'articles/a.md?view=full#details');
        $this->assertResolves('/about/#team', 'content/start.md', 'about.md#team');
        $this->assertResolves('/about/?x=1', 'content/start.md', 'about.md?x=1');
        $this->assertResolves('/notes/about/?view=full#details', 'content/start.md', 'about.md?view=full#details', notes: true);
    }

    #[Test]
    public function unicode_and_space_names_are_encoded_exactly_once(): void
    {
        $encoded = '/%C3%A7al%C4%B1%C5%9Fma%20notu/';

        $this->assertResolves($encoded, 'content/start.md', '%C3%A7al%C4%B1%C5%9Fma%20notu.md');
        $this->assertResolves($encoded, 'content/start.md', 'çalışma notu.md');
        $this->assertResolves($encoded.'#a', 'content/start.md', 'çalışma%20notu.md#a');
    }

    #[Test]
    public function a_decomposed_link_matches_the_composed_file_name(): void
    {
        $this->assertResolves('/%C3%A7al%C4%B1%C5%9Fma%20notu/', 'content/start.md', "c\u{0327}al\u{0131}s\u{0327}ma notu.md");
    }

    #[Test]
    public function links_to_external_items_go_to_their_destination(): void
    {
        $this->assertResolves('https://medium.com/@x/c', 'content/start.md', 'articles/c.md');
        $this->assertResolves('https://medium.com/@x/c', 'content/articles/a.md', 'c.md', notes: true);
    }

    // --- eligibility and missing targets ---------------------------------------------------------

    #[Test]
    public function a_draft_or_scheduled_target_is_a_not_eligible_error(): void
    {
        $this->assertFails('content/start.md', 'articles/draft.md', 'articles/draft.md', 'not part of this build', 'draft or scheduled');
        $this->assertFails('content/start.md', 'articles/future.md', 'not part of this build');
    }

    #[Test]
    public function a_draft_resolves_when_drafts_are_included(): void
    {
        $resolver = $this->resolver(drafts: true);

        $this->assertSame('/articles/draft/', $resolver->resolve('content/start.md', 'articles/draft.md', false));
    }

    #[Test]
    public function a_draft_page_is_not_eligible_either(): void
    {
        $resolver = $this->resolver(['content/start.md' => self::markdown('Start', ['draft' => 'true'])]);

        try {
            $resolver->resolve('content/about.md', 'start.md', false);
            $this->fail('Expected a ContentException');
        } catch (ContentException $exception) {
            $this->assertStringContainsString('not part of this build', $exception->getMessage());
        }
    }

    #[Test]
    public function a_missing_target_is_a_distinct_error(): void
    {
        $this->assertFails('content/start.md', 'nope.md', 'content/nope.md', 'does not exist');
        $this->assertFails('content/articles/a.md', '../missing/x.md?y=1#z', 'content/missing/x.md', 'does not exist');
    }

    #[Test]
    public function the_error_names_the_file_and_the_link(): void
    {
        try {
            $this->resolver()->resolve('content/articles/a.md', 'gone.md', true);
            $this->fail('Expected a ContentException');
        } catch (ContentException $exception) {
            $this->assertSame(
                'content error in content/articles/a.md: image "gone.md" points to content/articles/gone.md, which does not exist. Fix the path or create the file.',
                $exception->getMessage(),
            );
        }
    }

    #[Test]
    public function leaving_the_content_directory_is_an_error(): void
    {
        $this->assertFails('content/start.md', '../README.md', 'leaves the content/ directory');
        $this->assertFails('content/articles/a.md', '../../outside.md', 'leaves the content/ directory');
        $this->assertFails('content/articles/a.md', '../../../x.png', 'leaves the content/ directory');
    }

    // --- folders ---------------------------------------------------------------------------------

    #[Test]
    public function folders_and_yaml_files_are_not_link_targets_of_their_own(): void
    {
        // the articles folder has no page: a link to it, or to a file that is not Markdown, is left as written
        $this->assertResolves('../articles/', 'content/legal/privacy.md', '../articles/');
        $this->assertResolves('articles/', 'content/start.md', 'articles/');
        $this->assertResolves('articles/data.yaml', 'content/start.md', 'articles/data.yaml');
        $this->assertResolves('data.yaml', 'content/articles/a.md', 'data.yaml');
    }

    // --- attachments -----------------------------------------------------------------------------

    #[Test]
    public function attachments_of_the_own_bundle_resolve_to_their_output_url(): void
    {
        $this->assertResolves('/articles/b/diagram.png', 'content/articles/b/page.md', 'diagram.png');
        $this->assertResolves('/articles/b/diagram.png', 'content/articles/b/page.md', './diagram.png');
        $this->assertResolves('/articles/b/diagram.png?v=2#x', 'content/articles/b/page.md', 'diagram.png?v=2#x');
        $this->assertResolves('/articles/b/img/deep%20photo.png', 'content/articles/b/page.md', 'img/deep%20photo.png');
        $this->assertResolves('/articles/b/%C3%A7izim.png', 'content/articles/b/page.md', '%C3%A7izim.png');
        $this->assertResolves('/articles/b/%C3%A7izim.png', 'content/articles/b/page.md', 'çizim.png');
        $this->assertResolves('/notes/articles/b/diagram.png', 'content/articles/b/page.md', 'diagram.png', notes: true);
    }

    #[Test]
    public function an_attachment_follows_a_permalink_or_slug_change_of_its_page(): void
    {
        $resolver = $this->resolver(['content/articles/b/page.md' => self::markdown('B', ['date' => '2026-02-01', 'permalink' => '/essays/b/'])]);

        $this->assertSame('/essays/b/diagram.png', $resolver->resolve('content/articles/b/page.md', 'diagram.png', true));
    }

    #[Test]
    public function attachments_of_other_bundles_resolve_too(): void
    {
        $this->assertResolves('/articles/b/diagram.png', 'content/articles/a.md', 'b/diagram.png');
        $this->assertResolves('/articles/b/diagram.png?v=1#x', 'content/start.md', 'articles/b/diagram.png?v=1#x');
        $this->assertResolves('/articles/b/img/deep%20photo.png', 'content/about.md', 'articles/b/img/deep%20photo.png');
        $this->assertResolves('/articles/b/%C3%A7izim.png', 'content/legal/privacy.md', '../articles/b/çizim.png');
        $this->assertResolves('/notes/articles/b/diagram.png', 'content/articles/a.md', 'b/diagram.png', notes: true);
    }

    #[Test]
    public function an_attachment_follows_the_slug_or_permalink_of_its_own_bundle(): void
    {
        $slugged = $this->resolver(['content/articles/b/page.md' => self::markdown('B', ['date' => '2026-02-01', 'slug' => 'renamed'])]);
        $this->assertSame('/articles/renamed/diagram.png', $slugged->resolve('content/articles/a.md', 'b/diagram.png', true));

        $files = ['content/articles/b/page.md' => self::markdown('B', ['date' => '2026-02-01', 'permalink' => '/essays/b/'])];
        $this->assertSame('/essays/b/diagram.png', $this->resolver($files)->resolve('content/articles/a.md', 'b/diagram.png', true));
        $this->assertSame(
            '/notes/essays/b/diagram.png',
            $this->resolver($files, notesBase: true)->resolve('content/articles/a.md', 'b/diagram.png', true),
        );
        $this->assertSame(
            '/notes/essays/b/img/deep%20photo.png',
            $this->resolver($files, notesBase: true)->resolve('content/start.md', 'articles/b/img/deep%20photo.png', true),
        );
    }

    #[Test]
    public function an_attachment_of_an_ineligible_bundle_is_a_not_eligible_error(): void
    {
        $files = [
            'content/articles/hidden/page.md' => self::markdown('Hidden', ['date' => '2026-05-01', 'draft' => 'true']),
            'content/articles/hidden/photo.png' => 'png',
            'content/articles/hidden/img/deep.png' => 'png',
            'content/articles/later/page.md' => self::markdown('Later', ['date' => '2027-05-01']),
            'content/articles/later/photo.png' => 'png',
        ];

        $this->assertFailsWith($files, 'content/articles/a.md', 'hidden/photo.png', 'content/articles/hidden/photo.png', 'not part of this build');
        $this->assertFailsWith($files, 'content/start.md', 'articles/hidden/img/deep.png', 'content/articles/hidden/img/deep.png');
        $this->assertFailsWith($files, 'content/articles/a.md', 'later/photo.png', 'not part of this build');
        // a file that does not exist under the ineligible bundle is left alone
        $this->assertSame('hidden/none.png', $this->resolver($files)->resolve('content/articles/a.md', 'hidden/none.png', true));
        // with drafts the attachment resolves
        $this->assertSame(
            '/articles/hidden/photo.png',
            $this->resolver($files, drafts: true)->resolve('content/articles/a.md', 'hidden/photo.png', true),
        );
    }

    /**
     * @param  array<string, string>  $files
     */
    private function assertFailsWith(array $files, string $from, string $url, string ...$parts): void
    {
        try {
            $this->resolver($files)->resolve($from, $url, true);
        } catch (ContentException $exception) {
            $this->assertSame($from, $exception->sourceFile);
            foreach ($parts as $part) {
                $this->assertStringContainsString($part, $exception->getMessage());
            }

            return;
        }
        $this->fail('Expected a ContentException for '.$url);
    }

    #[Test]
    public function other_relative_paths_are_left_unchanged(): void
    {
        // not a file of any bundle: left for `verse check`
        $this->assertResolves('diagram.png', 'content/articles/a.md', 'diagram.png');
        $this->assertResolves('b/missing.png', 'content/articles/a.md', 'b/missing.png');
        $this->assertResolves('../b/missing.png', 'content/articles/a.md', '../b/missing.png');
        $this->assertResolves('missing.png', 'content/articles/b/page.md', 'missing.png');
        $this->assertResolves('docs/', 'content/start.md', 'docs/');
        $this->assertResolves('about', 'content/start.md', 'about');
        $this->assertResolves('file.pdf#page=2', 'content/start.md', 'file.pdf#page=2');
    }

    // --- URLs that are not rewritten / root-relative ---------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function untouched(): iterable
    {
        yield 'https' => ['https://example.org/a.md'];
        yield 'http with query' => ['http://example.org/?a=1#b'];
        yield 'mailto' => ['mailto:me@example.org'];
        yield 'tel' => ['tel:+123'];
        yield 'protocol relative' => ['//cdn.example.org/x.js'];
        yield 'fragment only' => ['#section'];
        yield 'query only' => ['?view=full'];
        yield 'empty' => [''];
        yield 'javascript (neutralised by CommonMark later)' => ['javascript:alert(1)'];
    }

    #[Test]
    #[DataProvider('untouched')]
    public function absolute_and_fragment_urls_are_unchanged(string $url): void
    {
        $this->assertResolves($url, 'content/articles/a.md', $url);
        $this->assertResolves($url, 'content/articles/a.md', $url, notes: true);
    }

    #[Test]
    public function root_relative_urls_get_the_base_path(): void
    {
        $this->assertResolves('/about/', 'content/articles/a.md', '/about/');
        $this->assertResolves('/robots.txt', 'content/articles/a.md', '/robots.txt');
        $this->assertResolves('/notes/about/', 'content/articles/a.md', '/about/', notes: true);
        $this->assertResolves('/notes/img/x%20y.png?v=1#f', 'content/articles/a.md', '/img/x%20y.png?v=1#f', notes: true);
        $this->assertResolves('/notes/', 'content/articles/a.md', '/', notes: true);
    }

    #[Test]
    public function root_relative_images_get_the_base_path_too(): void
    {
        $this->assertSame('/notes/img/logo.svg', $this->resolver(notesBase: true)->resolve('content/start.md', '/img/logo.svg', true));
    }

    // --- rendering through SiteMarkdown ----------------------------------------------------------

    #[Test]
    public function pages_render_with_resolved_links_and_attachment_figures(): void
    {
        $model = $this->model([
            'content/start.md' => self::markdown('Start', body: "[About](about.md#team) [bad id](articles/a.md?x=1) [root](/robots.txt) ![logo](/img/l.png)\n"),
        ], notesBase: true);
        $markdown = new SiteMarkdown($model);

        $home = $markdown->page($model->pageBySource('content/start.md') ?? $this->fail('no start page'));
        $this->assertStringContainsString('<a href="/notes/about/#team">About</a>', $home->html);
        $this->assertStringContainsString('<a href="/notes/articles/a/?x=1">bad id</a>', $home->html);
        $this->assertStringContainsString('<a href="/notes/robots.txt">root</a>', $home->html);
        $this->assertStringContainsString('<img src="/notes/img/l.png" alt="logo" />', $home->html);

        $b = $model->pageBySource('content/articles/b/page.md') ?? $this->fail('no b');
        $this->assertStringContainsString(
            '<figure><img src="/notes/articles/b/diagram.png" alt="fig" /><figcaption>Caption</figcaption></figure>',
            $markdown->page($b)->html,
        );
    }

    #[Test]
    public function a_broken_link_in_a_page_names_the_page(): void
    {
        $model = $this->model(['content/about.md' => self::markdown('About', body: "See [it](articles/draft.md).\n")]);
        $page = $model->pageBySource('content/about.md') ?? $this->fail('no about');

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('content error in content/about.md: link "articles/draft.md"');

        (new SiteMarkdown($model))->page($page);
    }

    #[Test]
    public function the_model_records_sources_dropped_as_not_eligible(): void
    {
        $model = $this->model();
        $this->assertSame(['content/articles/draft.md', 'content/articles/future.md'], $model->excludedSources);

        $this->assertSame([], $this->model(drafts: true)->excludedSources);

        $start = $this->model(['content/start.md' => self::markdown('Start', ['draft' => 'true'])]);
        $this->assertContains('content/start.md', $start->excludedSources);
        $this->assertNull($start->pageBySource('content/start.md'));
    }
}
