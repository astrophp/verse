<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Build;

use Astro\Verse\Assets\ViteManifest;
use Astro\Verse\Tests\Support\Html;
use Astro\Verse\Tests\Support\TestCase;
use Astro\Verse\Theme\ThemeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Builds the `notes` fixture site at `/` and `/notes/`.
 */
final class NotesSiteBuildTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function basePaths(): array
    {
        return [
            'root' => ['https://example.test/', '/'],
            'subdirectory' => ['https://example.test/notes/', '/notes/'],
        ];
    }

    /**
     * Builds the fixture with the given `url` and returns the site root.
     */
    private function built(string $url): string
    {
        $root = $this->fixtureSite('notes', [
            'verse.yaml' => (string) preg_replace('~^url: .*$~m', 'url: '.$url, (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sites/notes/verse.yaml')),
        ]);
        $result = $this->buildSite($root);
        $this->assertSame([], $result->warnings);

        return $root;
    }

    private static function xpath(string $html): Html
    {
        return Html::parse($html);
    }

    private function page(string $root, string $path): string
    {
        $html = file_get_contents($root.'/dist/'.$path);
        $this->assertIsString($html);

        return $html;
    }

    #[Test]
    #[DataProvider('basePaths')]
    public function asset_tags_are_deduplicated_base_prefixed_and_every_manifest_file_is_copied(string $url, string $base): void
    {
        $root = $this->built($url);
        $manifest = ViteManifest::load(ThemeRegistry::bundled()->find('default'));

        foreach (['index.html', 'essays/trace/index.html', '404.html', 'tags/laravel/index.html', 'tags/index.html'] as $path) {
            $html = $this->page($root, $path);
            $this->assertSame(1, preg_match_all('~<link rel="stylesheet" href="'.preg_quote($base, '~').'assets/theme/app-[\w-]+\.css">~', $html), $path);
            $this->assertSame(1, preg_match_all('~<script type="module" src="'.preg_quote($base, '~').'assets/theme/app-[\w-]+\.js"></script>~', $html), $path);
            $this->assertSame(1, substr_count($html, '<link rel="stylesheet"'));
            $this->assertSame(1, substr_count($html, '<script type="module"'));
        }

        $this->assertCount(6, $manifest->files());
        foreach ($manifest->files() as $file) {
            $this->assertFileExists($root.'/dist/assets/theme/'.$file);
        }
    }

    #[Test]
    public function the_output_contains_only_public_files(): void
    {
        $root = $this->built('https://example.test/');
        $files = array_keys(self::snapshot($root.'/dist'));

        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression('~(^|/)\.vite/|manifest\.json$|\.map$|\.md$|\.ya?ml$|\.blade\.php$|\.php$~', $file);
        }
        $this->assertContains('.verse-output', $files);
        $this->assertContains('humans.txt', $files);
        $this->assertContains('.well-known/security.txt', $files);
        $this->assertNotContains('.hidden', $files);
        $this->assertContains('essays/trace/diagram.svg', $files);
        $this->assertContains('404.html', $files);
        $this->assertContains('tags/index.html', $files);
        $this->assertNotContains('articles/trace/index.html', $files);
        $this->assertContains('articles/index.html', $files);
        $this->assertNotContains('page/2/index.html', $files);
        $this->assertCount(4, array_filter($files, static fn (string $f): bool => str_ends_with($f, '.woff2')));
    }

    #[Test]
    #[DataProvider('basePaths')]
    public function menu_content_and_attachment_links_and_canonicals_follow_the_base_path(string $url, string $base): void
    {
        $root = $this->built($url);

        $home = $this->page($root, 'index.html');
        $article = $this->page($root, 'essays/trace/index.html');
        $about = $this->page($root, 'about/index.html');

        foreach (['', 'articles/', 'tags/', 'projects/', 'about/'] as $route) {
            $this->assertStringContainsString('<a class="nav-link" href="'.$base.$route.'"', $home);
        }
        $this->assertStringContainsString('<link rel="canonical" href="https://example.test'.$base.'">', $home);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.test'.$base.'essays/trace/">', $article);
        $this->assertStringContainsString('<meta property="og:image" content="https://example.test'.$base.'essays/trace/diagram.svg">', $article);
        $this->assertStringContainsString('<img src="'.$base.'essays/trace/diagram.svg"', $article);
        $this->assertStringContainsString('href="'.$base.'articles/older-note/">the older note</a>', $article);
        $this->assertStringContainsString('href="'.$base.'essays/trace/">the trace article</a>', $about);
        $this->assertStringContainsString('<a class="brand" href="'.$base.'"', $home);
        $this->assertStringNotContainsString('Browse by tag', $home);
        $this->assertStringContainsString('<a class="nav-link" href="'.$base.'tags/">Tags</a>', $home);
        $this->assertStringContainsString('href="'.$base.'tags/laravel/">Laravel</a>', $home);

        // pages in subdirectories get the same absolute, base-prefixed URLs (also the 404 page)
        $this->assertSame(1, self::xpath($this->page($root, '404.html'))->count('//div[@class="not-found"]//a[@href="'.$base.'articles/"][contains(.,"Back to articles")]'));
        $this->assertStringContainsString('href="'.$base.'assets/theme/', $this->page($root, 'legal/privacy/index.html'));
    }

    #[Test]
    public function the_javascript_markup_contracts_are_met(): void
    {
        $root = $this->built('https://example.test/');
        $xpath = self::xpath($this->page($root, 'essays/trace/index.html'));

        $this->assertSame('en', $xpath->attr('//html', 'lang'));
        $this->assertNull($xpath->attr('//html', 'data-theme'));
        $this->assertSame(1, $xpath->count('//header[@id="site-header"]'));
        $this->assertSame(1, $xpath->count('//button[@id="theme-button"][@hidden]'));
        $this->assertSame(1, $xpath->count('//div[@id="theme-options"][@hidden]'));
        $this->assertSame(3, $xpath->count('//div[@id="theme-options"]//*[@data-theme-option]'));
        $this->assertSame(1, $xpath->count('//*[@id="reading-progress"]'));
        $this->assertSame(1, $xpath->count('//meta[@name="theme-color"][@content="#fcfcfc"]'));
        $this->assertSame(1, $xpath->count('//meta[@name="color-scheme"][@content="light dark"]'));
        $this->assertSame(1, $xpath->count('//main[@id="main-content"][@tabindex="-1"]'));
        $this->assertGreaterThanOrEqual(2, $xpath->count('//div[contains(@class,"code-block")]/div[@class="code-header"]/following-sibling::pre//code'));
        $this->assertSame(1, $xpath->count('//script[not(@src)][contains(.,"verse-theme")]'));

        // the TOC, its mobile copy and the headings share one id string
        $ids = $xpath->attrs('//article[contains(@class,"prose")]//h2[@id] | //article[contains(@class,"prose")]//h3[@id]', 'id');
        $this->assertCount(5, $ids);
        $this->assertSame($ids, $xpath->attrs('//aside[contains(@class,"toc")]//a[@data-toc]', 'data-toc'));
        $this->assertSame(array_map(static fn (string $id): string => '#'.$id, $ids), $xpath->attrs('//aside[contains(@class,"toc")]//a[@data-toc]', 'href'));
        $this->assertSame($ids, $xpath->attrs('//details[contains(@class,"mobile-toc")]//a[@data-toc]', 'data-toc'));
        $this->assertSame(1, $xpath->count('//aside[contains(@class,"toc")]//a[@aria-current="true"]'));
        $this->assertSame(5, $xpath->count('//details[contains(@class,"mobile-toc")]//a[@data-toc]'));

        // article structure
        $this->assertSame(1, $xpath->count('//a[contains(@class,"back-link")][@href="/articles/"][contains(.,"Back to articles")]'));
        $this->assertSame(1, $xpath->count('//time[@datetime="2026-10-10"][.="10 October 2026"]'));
        $this->assertSame(1, $xpath->count('//div[contains(@class,"article-end")]//a[contains(.,"Back to articles")]'));
        $this->assertSame(1, $xpath->count('//div[contains(@class,"related-article")]//a[contains(.,"An older fixture note")]'));
        $this->assertSame(1, $xpath->count('//figure/figcaption'));
        $this->assertSame(1, $xpath->count('//div[@class="table-scroll"]//table'));
        $this->assertSame(2, $xpath->count('//div[contains(@class,"callout")]/p[@class="callout-title"]'));
        $this->assertSame(1, $xpath->count('//div[contains(@class,"footnotes")]'));
        $this->assertSame(1, $xpath->count('//meta[@property="og:type"][@content="article"]'));
        $this->assertSame(0, $xpath->count('//meta[@name="robots"]'));
    }

    #[Test]
    public function header_footer_and_head_follow_the_site_configuration(): void
    {
        $root = $this->built('https://example.test/');
        $home = $this->page($root, 'index.html');
        $xpath = self::xpath($home);

        $this->assertSame('Notes', $xpath->text('//span[@class="brand-name"]'));
        $this->assertSame('A fixture site for the Verse test suite', $xpath->text('//span[@class="brand-role"]'));
        $this->assertStringContainsString('aria-label="Notes, A fixture site for the Verse test suite — home"', $home);
        $this->assertStringContainsString('<title>Notes</title>', $home);
        $this->assertStringContainsString('<title>The bug hiding in a successful trace — Notes</title>', $this->page($root, 'essays/trace/index.html'));
        $this->assertStringContainsString('<div>© 2026 Fixture Author</div>', $home);
        $this->assertStringContainsString('<div class="footer-credit">Built with <a href="https://github.com/astrophp/verse" rel="noopener">Verse</a></div>', $home);

        $this->assertSame(2, $xpath->count('//div[@class="footer-social"]/a'));
        $this->assertSame(['_blank', '_blank'], $xpath->attrs('//div[@class="footer-social"]/a', 'target'));
        $this->assertSame(['noopener noreferrer', 'noopener noreferrer'], $xpath->attrs('//div[@class="footer-social"]/a', 'rel'));

        // the menu marks the section the page is in
        $this->assertSame('Home', self::xpath($home)->text('//a[@aria-current="page"]'));
        $this->assertSame('Articles', self::xpath($this->page($root, 'articles/index.html'))->text('//a[@aria-current="page"]'));
        $this->assertSame('Articles', self::xpath($this->page($root, 'articles/older-note/index.html'))->text('//a[@aria-current="page"]'));
        // a permalinked article still belongs to the Articles menu item
        $this->assertSame('Articles', self::xpath($this->page($root, 'essays/trace/index.html'))->text('//a[@aria-current="page"]'));
        $this->assertSame(1, self::xpath($this->page($root, 'essays/trace/index.html'))->count('//a[@aria-current="page"]'));
        $this->assertSame('Tags', self::xpath($this->page($root, 'tags/laravel/index.html'))->text('//a[@aria-current="page"]'));
        $this->assertSame('Tags', self::xpath($this->page($root, 'tags/index.html'))->text('//a[@aria-current="page"]'));

        // tag pages have no description of their own: the site description is their meta description
        foreach (['tags/index.html', 'tags/laravel/index.html'] as $tagPage) {
            $this->assertSame(
                'Fixture notes on architecture, AI systems and reliable software. Not a real site.',
                self::xpath($this->page($root, $tagPage))->attr('//meta[@name="description"]', 'content'),
            );
        }
        $this->assertSame(['Home', 'Articles', 'Tags', 'Projects', 'About'], self::xpath($home)->texts('//nav[@class="header-nav"]/a'));
        $this->assertSame('About', self::xpath($this->page($root, 'about/index.html'))->text('//a[@aria-current="page"]'));
        $this->assertSame(0, self::xpath($this->page($root, '404.html'))->count('//a[@aria-current="page"]'));

        // the default icon is the monogram of the site title
        $this->assertStringContainsString('>n</text>', Html::inlineIcon($home));
    }

    #[Test]
    public function the_external_item_links_out_with_a_screen_reader_suffix(): void
    {
        $root = $this->built('https://example.test/');
        $xpath = self::xpath($this->page($root, 'index.html'));

        $link = '//a[contains(@class,"timeline-article")][contains(@href,"blog.example.test")]';
        $this->assertSame('_blank', $xpath->attr($link, 'target'));
        $this->assertSame('noopener noreferrer', $xpath->attr($link, 'rel'));
        $this->assertSame(1, $xpath->count('//a[contains(@href,"blog.example.test")]//span[@class="sr-only"][.="(opens in a new tab)"]'));
        // an external entry shows the outbound arrow, a local one the right arrow
        $this->assertSame(1, $xpath->count($link.'//span[@class="timeline-arrow"]/svg/path[@d="M6 18 18 6M6 6h12v12"]'));
        $this->assertSame(0, $xpath->count($link.'//span[@class="timeline-arrow"]/svg/path[@d="M4 12h15m-6-6 6 6-6 6"]'));
        $local = '//a[contains(@class,"timeline-article")][not(@target)]';
        $this->assertGreaterThan(0, $xpath->count($local));
        $this->assertSame($xpath->count($local), $xpath->count($local.'//span[@class="timeline-arrow"]/svg/path[@d="M4 12h15m-6-6 6 6-6 6"]'));
        $this->assertSame(0, $xpath->count($local.'//span[@class="sr-only"]'));
        $this->assertStringNotContainsString('Read on Medium', $this->page($root, 'index.html'));
        $this->assertSame(1, $xpath->count('//time[@datetime="2026-10-15"][.="15 Oct 2026"]'));
        $this->assertSame(3, $xpath->count('//a[contains(@href,"blog.example.test")]/ancestor::div[@class="timeline-entry"]//a[@class="tag-link"]'));
        $this->assertFileDoesNotExist($root.'/dist/articles/published-elsewhere/index.html');
    }

    #[Test]
    public function the_home_page_lists_the_latest_articles_by_month_without_count_or_pagination(): void
    {
        $root = $this->built('https://example.test/');
        $xpath = self::xpath($this->page($root, 'index.html'));

        $this->assertSame(1, $xpath->count('//h1'));
        $this->assertSame('Notes', $xpath->text('//h1[@class="sr-only"]'));
        // the site description is the visible introduction (no visible heading), and the page is not "bare"
        $this->assertSame(['Fixture notes on architecture, AI systems and reliable software. Not a real site.'], $xpath->texts('//header[@class="page-intro"]/p'));
        $this->assertSame(0, $xpath->count('//header[@class="page-intro"]/h1'));
        $this->assertSame(0, $xpath->count('//div[contains(@class,"timeline-page-bare")]'));
        $this->assertSame(
            'Fixture notes on architecture, AI systems and reliable software. Not a real site.',
            $xpath->attr('//meta[@name="description"]', 'content'),
        );

        $this->assertSame('Latest articles', $xpath->text('//span[@class="archive-section-label"]'));
        $this->assertSame(0, $xpath->count('//div[@class="archive-count"]'));
        $this->assertSame(['2026 Oct', '2025 Jun'], $xpath->texts('//div[@class="timeline-year"]'));
        $this->assertSame(['Published in October 2026', 'Published in June 2025'], $xpath->attrs('//section[contains(@class,"timeline-group")]', 'aria-label'));
        $this->assertSame(3, $xpath->count('//article[@class="timeline-item"]'));
        $this->assertSame(0, $xpath->count('//nav[contains(@class,"pagination")]'));
        $this->assertSame(0, $xpath->count('//*[contains(@class,"empty-state")]'));

        $all = '//div[@class="archive-more"]/a[@class="button-link"][@href="/articles/"]';
        $this->assertSame(1, $xpath->count($all));
        $this->assertSame('All articles', $xpath->text($all));
        $this->assertSame(1, $xpath->count($all.'/svg'));
        $this->assertSame(0, $xpath->count('//*[@aria-current="true"]'));
    }

    #[Test]
    public function the_archive_page_has_a_visible_heading_the_count_and_month_groups(): void
    {
        $root = $this->built('https://example.test/notes/');
        $html = $this->page($root, 'articles/index.html');
        $xpath = self::xpath($html);

        $this->assertSame('Articles', $xpath->text('//h1'));
        $this->assertSame(1, $xpath->count('//h1'));
        $this->assertSame(0, $xpath->count('//header[@class="page-intro"]/p'));
        $this->assertSame('All articles', $xpath->text('//span[@class="archive-section-label"]'));
        $this->assertSame('3 articles', $xpath->text('//div[@class="archive-count"]'));
        $this->assertSame(['2026 Oct', '2025 Jun'], $xpath->texts('//div[@class="timeline-year"]'));
        $this->assertSame(3, $xpath->count('//article[@class="timeline-item"]'));
        $this->assertSame(0, $xpath->count('//nav[contains(@class,"pagination")]'));
        $this->assertSame(0, $xpath->count('//a[contains(.,"All articles")]'));
        $this->assertSame(
            'Fixture notes on architecture, AI systems and reliable software. Not a real site.',
            $xpath->attr('//meta[@name="description"]', 'content'),
        );
        $this->assertStringContainsString('<title>Articles — Notes</title>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.test/notes/articles/">', $html);
    }

    #[Test]
    public function drafts_and_scheduled_items_are_absent_everywhere(): void
    {
        $root = $this->built('https://example.test/');

        foreach (self::snapshot($root.'/dist') as $path => $contents) {
            $this->assertStringNotContainsString('Draft notes', $contents, $path);
            $this->assertStringNotContainsString('Scheduled for later', $contents, $path);
            $this->assertStringNotContainsString('draft-notes', $contents, $path);
            if (str_ends_with((string) $path, '.html')) {
                $this->assertStringNotContainsString('state-badge', $contents, $path);
                $this->assertStringNotContainsString('state-line', $contents, $path);
            }
        }
        $this->assertStringNotContainsString('"state"', (string) file_get_contents($root.'/dist/search.json'));
        $this->assertFileDoesNotExist($root.'/dist/articles/draft-notes/index.html');
        $this->assertFileDoesNotExist($root.'/dist/articles/future-article/index.html');
        $this->assertFileDoesNotExist($root.'/dist/tags/secret/index.html');
        $this->assertFileDoesNotExist($root.'/dist/tags/soon/index.html');
    }

    #[Test]
    public function the_page_role_has_the_reading_measure_without_a_toc(): void
    {
        $root = $this->built('https://example.test/');
        $xpath = self::xpath($this->page($root, 'about/index.html'));

        $this->assertSame('About', $xpath->text('//header[@class="page-intro"]/h1'));
        $this->assertSame('About the fixture author', $xpath->text('//header[@class="page-intro"]/p'));
        $this->assertSame(1, $xpath->count('//div[@class="article-layout"]/div/article[@class="prose"]'));
        $this->assertSame(0, $xpath->count('//aside'));
        $this->assertSame(0, $xpath->count('//details'));
        $this->assertStringContainsString('<a href="/essays/trace/">the trace article</a>', $this->page($root, 'about/index.html'));
        $this->assertStringContainsString('<a href="/projects/">projects</a>', $this->page($root, 'about/index.html'));
    }

    #[Test]
    public function the_tag_index_and_tag_archives_render(): void
    {
        $root = $this->built('https://example.test/notes/');
        $index = self::xpath($this->page($root, 'tags/index.html'));

        $this->assertSame('Tags', $index->text('//h1'));
        $this->assertSame(3, $index->count('//a[@class="tag-index-row"]'));
        $this->assertSame(
            ['AI systems', 'Laravel', 'Observability'],
            $index->texts('//a[@class="tag-index-row"]//h2'),
        );
        $this->assertSame(0, $index->count('//header[@class="page-intro tags-intro"]//a'));
        $this->assertSame(0, $index->count('//header[@class="page-intro tags-intro"]/p'));
        $this->assertSame(1, $index->count('//header[@class="page-intro tags-intro"]/h1/following-sibling::div[@class="tag-filter"]'));
        $this->assertSame(1, $index->count('//header[@class="page-intro tags-intro"]/div[@class="tag-filter"][@hidden]/label[@class="sr-only"][@for="tag-filter-input"][.="Filter tags"]'));
        $this->assertSame(1, $index->count('//div[@class="tag-filter"]/input[@id="tag-filter-input"][@type="search"][@placeholder="Filter tags…"]'));
        $this->assertSame(1, $index->count('//div[@class="tag-filter"]/span[@class="tag-filter-icon"]/svg'));
        $this->assertSame(['AI systems', 'Laravel', 'Observability'], $index->attrs('//a[@class="tag-index-row"]', 'data-tag-label'));
        $this->assertSame(1, $index->count('//p[@class="tag-filter-empty"][@hidden][.="No tags match."]'));

        $archive = self::xpath($this->page($root, 'tags/laravel/index.html'));
        $this->assertSame('#Laravel', $archive->text('//h1'));
        $this->assertSame(1, $archive->count('//h1/span[@class="tag-hash"][@aria-hidden="true"][.="#"]'));
        $this->assertSame(0, $archive->count('//header[@class="page-intro"]/p'));
        $this->assertSame(1, $archive->count('//a[contains(@class,"tag-back")][@href="/notes/tags/"][contains(.,"All tags")]'));
        $this->assertSame(0, $archive->count('//div[@class="archive-toolbar"] | //span[@class="archive-section-label"]'));
        $this->assertSame('2 articles', $archive->text('//div[@class="archive-count"]'));
        $this->assertSame(0, $archive->count('//div[@class="archive-more"]'));

        // only the browsed tag is marked current, and only on its own archive
        $current = '//a[@class="tag-link"][@aria-current="true"]';
        $this->assertSame(2, $archive->count($current));
        $this->assertSame(['Laravel', 'Laravel'], $archive->texts($current));
        $this->assertGreaterThan(0, $archive->count('//a[@class="tag-link"][not(@aria-current)]'));
        $this->assertSame(0, self::xpath($this->page($root, 'index.html'))->count('//a[@aria-current="true"]'));
        $this->assertSame(0, self::xpath($this->page($root, 'articles/index.html'))->count('//a[@aria-current="true"]'));
        $other = self::xpath($this->page($root, 'tags/observability/index.html'));
        $this->assertSame(['Observability'], array_values(array_unique($other->texts('//a[@aria-current="true"]'))));
        $this->assertSame('2 articles', $other->text('//div[@class="archive-count"]'));
        $this->assertSame(0, $other->count('//div[@class="archive-toolbar"]'));
    }

    #[Test]
    public function two_builds_with_the_same_inputs_are_identical_except_for_the_marker(): void
    {
        $root = $this->built('https://example.test/');
        $first = self::snapshot($root.'/dist');

        $this->buildSite($root);
        $second = self::snapshot($root.'/dist');

        unset($first['.verse-output'], $second['.verse-output']);
        $this->assertSame($first, $second);

        // and with the compiled view cache gone
        self::removeTree($root.'/.verse/cache');
        $this->buildSite($root);
        $third = self::snapshot($root.'/dist');
        unset($third['.verse-output']);
        $this->assertSame($first, $third);
    }

    #[Test]
    public function the_view_cache_holds_compiled_views_but_no_content(): void
    {
        $root = $this->built('https://example.test/');
        $cache = self::snapshot($root.'/.verse/cache/views');

        $this->assertNotSame([], $cache);
        foreach ($cache as $path => $contents) {
            $this->assertStringNotContainsString('hiding in a successful', $contents, $path);
            $this->assertStringNotContainsString('echo $x', $contents, $path);
            $this->assertStringNotContainsString('fixture content', $contents, $path);
        }
    }

    #[Test]
    public function php_and_blade_fences_stay_literal_and_nothing_executes(): void
    {
        $root = $this->built('https://example.test/');
        $article = $this->page($root, 'essays/trace/index.html');

        $this->assertStringContainsString('&lt;?php', $article);
        $this->assertStringNotContainsString('<?php', $article);
        $this->assertStringContainsString('{{ <span class="hl-variable">$x</span> }}', $article);
        $this->assertStringContainsString('&lt;<span class="hl-keyword">x-foo</span>/&gt;', $article);
        $this->assertStringContainsString('<span class="hl-keyword">@if</span>', $article);
        $this->assertStringNotContainsString('<x-foo', $article);
    }
}
