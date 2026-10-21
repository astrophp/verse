<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Build;

use Astro\Verse\Error\ContentException;
use Astro\Verse\Tests\Support\Html;
use Astro\Verse\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fixture variants: pagination, empty site, optional data, settings and preview.
 */
final class BuildVariantsTest extends TestCase
{
    private static function xpath(string $html): Html
    {
        return Html::parse($html);
    }

    private function html(string $root, string $path): string
    {
        $html = file_get_contents($root.'/dist/'.$path);
        $this->assertIsString($html);

        return $html;
    }

    private function text(Html $xpath, string $query): string
    {
        return $xpath->text($query);
    }

    #[Test]
    public function the_archive_and_tag_archives_paginate_ten_per_page_and_the_home_page_shows_five(): void
    {
        $files = [];
        for ($i = 1; $i <= 10; $i++) {
            $files[sprintf('content/articles/extra-%02d.md', $i)] = self::markdown('Extra '.$i, ['date' => sprintf('2026-01-%02d', $i), 'tags' => '[Laravel]']);
        }
        $root = $this->fixtureSite('notes', $files);
        $this->buildSite($root);

        // the home page: the 5 newest, no count, no pagination, no further pages
        $home = self::xpath($this->html($root, 'index.html'));
        $this->assertSame(5, $home->count('//article[@class="timeline-item"]'));
        $this->assertSame(0, $home->count('//div[@class="archive-count"] | //nav[contains(@class,"pagination")]'));
        $this->assertFileDoesNotExist($root.'/dist/page/2/index.html');

        // 13 eligible articles, 10 per page
        $first = self::xpath($this->html($root, 'articles/index.html'));
        $this->assertSame('13 articles', $this->text($first, '//div[@class="archive-count"]'));
        $this->assertSame(10, $first->count('//article[@class="timeline-item"]'));
        $this->assertSame('Page 1 of 2', $this->text($first, '//nav[@aria-label="Pagination"]/span[@class="pagination-status"]'));
        $this->assertSame('Newer', $this->text($first, '//nav[@aria-label="Pagination"]/span[@class="pagination-disabled"]'));
        $this->assertSame('/articles/page/2/', $first->attr('//nav[@class="pagination"]/a[@rel="next"]', 'href'));
        $this->assertSame('Older', $this->text($first, '//nav/a[@rel="next"]'));

        $second = self::xpath($this->html($root, 'articles/page/2/index.html'));
        $this->assertSame('Page 2 of 2', $this->text($second, '//span[@class="pagination-status"]'));
        $this->assertSame('/articles/', $second->attr('//nav[@class="pagination"]/a[@rel="prev"]', 'href'));
        $this->assertSame('Older', $this->text($second, '//span[@class="pagination-disabled"]'));
        $this->assertSame('13 articles', $this->text($second, '//div[@class="archive-count"]'));
        $this->assertSame(3, $second->count('//article[@class="timeline-item"]'));
        $this->assertSame('Articles', $second->text('//h1'));
        $this->assertSame('Articles', $second->text('//a[@aria-current="page"]'));
        $this->assertStringContainsString('<link rel="canonical" href="https://example.test/articles/page/2/">', $this->html($root, 'articles/page/2/index.html'));
        $this->assertFileDoesNotExist($root.'/dist/articles/page/3/index.html');

        // a tag archive (Laravel: 12 items) paginates the same way, under /tags/
        $this->assertFileExists($root.'/dist/tags/laravel/page/2/index.html');
        $this->assertFileDoesNotExist($root.'/dist/tags/laravel/page/3/index.html');
        $archive = self::xpath($this->html($root, 'tags/laravel/page/2/index.html'));
        $this->assertSame('12 articles', $this->text($archive, '//div[@class="archive-count"]'));
        $this->assertSame('/tags/laravel/', $archive->attr('//a[@rel="prev"]', 'href'));
        $this->assertSame(0, self::xpath($this->html($root, 'tags/observability/index.html'))->count('//nav[@class="pagination"]'));
    }

    #[Test]
    public function a_site_without_articles_shows_the_empty_state_on_the_home_page_the_archive_and_the_tag_index(): void
    {
        $root = $this->fixtureSite('notes', ['content/articles' => null, 'content/about.md' => self::markdown('About', ['menu' => 'true', 'order' => '3'])]);
        $this->buildSite($root);

        $home = self::xpath($this->html($root, 'index.html'));
        $this->assertSame('Nothing published yet.', $this->text($home, '//div[contains(@class,"empty-state")]/h2'));
        $this->assertSame(1, $home->count('//div[@class="archive-more"]/a[@class="button-link"][contains(.,"All articles")][@href="/articles/"]'));
        $this->assertSame(0, $home->count('//div[contains(@class,"empty-state")]//a'));
        $this->assertSame(['Home', 'Articles', 'Tags', 'Projects', 'About'], $home->texts('//nav[@class="header-nav"]/a'));
        $this->assertSame('Home', $home->text('//a[@aria-current="page"]'));

        $archive = self::xpath($this->html($root, 'articles/index.html'));
        $this->assertSame('Nothing published yet.', $this->text($archive, '//div[contains(@class,"empty-state")]/h2'));
        $this->assertSame('0 articles', $this->text($archive, '//div[@class="archive-count"]'));
        $this->assertSame(0, $archive->count('//nav[@class="pagination"] | //section[contains(@class,"timeline-group")]'));
        $this->assertSame('Articles', $archive->text('//a[@aria-current="page"]'));
        $this->assertFileDoesNotExist($root.'/dist/articles/page/2/index.html');

        $tags = self::xpath($this->html($root, 'tags/index.html'));
        $this->assertSame('Tags', $tags->text('//h1'));
        $this->assertSame('No tags yet.', $tags->text('//div[@class="tags-page"]/p'));
        $this->assertSame(0, $tags->count('//a[@class="tag-index-row"]'));
        $this->assertSame(0, $tags->count('//*[contains(@class,"tag-filter")] | //input'));
        $this->assertSame('Tags', $tags->text('//a[@aria-current="page"]'));
    }

    #[Test]
    public function an_empty_articles_folder_behaves_like_a_missing_one(): void
    {
        $root = $this->fixtureSite('notes', ['content/articles' => null, 'content/articles/.keep' => '', 'content/about.md' => self::markdown('About')]);
        $this->buildSite($root);

        $this->assertSame('0 articles', $this->text(self::xpath($this->html($root, 'articles/index.html')), '//div[@class="archive-count"]'));
        $this->assertFileExists($root.'/dist/tags/index.html');
    }

    #[Test]
    public function the_timeline_groups_contiguous_months_across_year_boundaries(): void
    {
        $root = $this->fixtureSite('notes', [
            'content/articles' => null,
            'content/about.md' => self::markdown('About'),
            'content/articles/a.md' => self::markdown('A', ['date' => '2026-01-20', 'tags' => '[x]']),
            'content/articles/b.md' => self::markdown('B', ['date' => '2026-01-05', 'tags' => '[x]']),
            'content/articles/c.md' => self::markdown('C', ['date' => '2025-12-30']),
            'content/articles/d.md' => self::markdown('D', ['date' => '2025-01-10']),
        ]);
        $this->buildSite($root);

        foreach (['index.html', 'articles/index.html'] as $path) {
            $xpath = self::xpath($this->html($root, $path));
            $this->assertSame(['2026 Jan', '2025 Dec', '2025 Jan'], $xpath->texts('//div[@class="timeline-year"]'), $path);
            $this->assertSame(
                ['Published in January 2026', 'Published in December 2025', 'Published in January 2025'],
                $xpath->attrs('//section[contains(@class,"timeline-group")]', 'aria-label'),
                $path,
            );
            $this->assertSame(['A', 'B'], $xpath->texts('(//section[contains(@class,"timeline-group")])[1]//h2'), $path);
            // the entry keeps its own full date label
            $this->assertSame('20 Jan 2026', $xpath->text('//time[@datetime="2026-01-20"]'), $path);
        }
        // tag archives group the same way
        $this->assertSame(['2026 Jan'], self::xpath($this->html($root, 'tags/x/index.html'))->texts('//div[@class="timeline-year"]'));
    }

    #[Test]
    public function month_labels_follow_the_site_language_and_timezone(): void
    {
        $yaml = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sites/notes/verse.yaml');
        $root = $this->fixtureSite('notes', [
            'verse.yaml' => $yaml."language: tr\ntimezone: Europe/Istanbul\n",
            'content/articles' => null,
            'content/about.md' => self::markdown('About'),
            'content/articles/a.md' => self::markdown('A', ['date' => '2026-10-15']),
            // 22:30 UTC on 30 Sep is already 1 Oct in Istanbul
            'content/articles/b.md' => self::markdown('B', ['date' => '2026-09-30T22:30:00Z']),
        ]);
        $this->buildSite($root);
        $xpath = self::xpath($this->html($root, 'articles/index.html'));

        $this->assertSame(['2026 Eki'], $xpath->texts('//div[@class="timeline-year"]'));
        $this->assertSame(['Published in Ekim 2026'], $xpath->attrs('//section[contains(@class,"timeline-group")]', 'aria-label'));
        $this->assertSame(2, $xpath->count('//section[contains(@class,"timeline-group")]//article'));
    }

    #[Test]
    public function theme_toc_false_removes_the_table_of_contents_and_short_articles_have_none(): void
    {
        $root = $this->fixtureSite('notes', [
            'verse.yaml' => (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sites/notes/verse.yaml')."theme:\n  toc: false\n",
        ]);
        $this->buildSite($root);
        $article = self::xpath($this->html($root, 'essays/trace/index.html'));

        $this->assertSame(0, $article->count('//aside | //details | //*[@data-toc]'));
        $this->assertSame(5, $article->count('//article[@class="prose"]//*[self::h2 or self::h3][@id]'));
        $this->assertSame(1, $article->count('//div[@class="article-layout"]'));

        // default setting: a single-heading article has no TOC either
        $root = $this->fixtureSite('notes', [
            'content/articles/short.md' => self::markdown('Short', ['date' => '2026-02-02'], "## Only one\n\nText.\n"),
        ]);
        $this->buildSite($root);
        $this->assertSame(0, self::xpath($this->html($root, 'articles/short/index.html'))->count('//aside | //details'));
    }

    /**
     * The emitted accent rules as selector => value, in order, for `index.html` of a site with the given theme yaml.
     *
     * @return list<string>
     */
    private function accentRules(string $themeYaml): array
    {
        $yaml = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sites/notes/verse.yaml');
        $root = $this->fixtureSite('notes', ['verse.yaml' => $yaml.$themeYaml]);
        $this->buildSite($root);
        $html = $this->html($root, 'index.html');

        if (preg_match('~<style>(.*?)</style>~', $html, $match) !== 1) {
            return [];
        }
        $this->assertGreaterThan((int) strpos($html, 'app-'), (int) strpos($html, '<style'), 'the accent style follows @verseAssets');

        return explode('|', str_replace(['}@media', '}}@', '}:root', '}}'], ['}|@media', '}}|@', '}|:root', '}}'], $match[1]));
    }

    #[Test]
    public function the_accent_style_is_emitted_only_when_set(): void
    {
        $none = $this->fixtureSite();
        $this->buildSite($none);
        $this->assertStringNotContainsString('<style', $this->html($none, 'index.html'));
        $this->assertSame([], $this->accentRules(''));
    }

    #[Test]
    public function accent_rules_are_emitted_per_mode_and_never_cross_modes(): void
    {
        $lightRules = [
            ':root[data-theme=light]{--accent:#111111}',
            '@media not (prefers-color-scheme:dark){:root:not([data-theme=dark]){--accent:#111111}}',
        ];
        $darkRules = [
            ':root[data-theme=dark]{--accent:#eeeeee}',
            '@media (prefers-color-scheme:dark){:root:not([data-theme=light]){--accent:#eeeeee}}',
        ];

        // light only: dark mode keeps the theme's own colour
        $this->assertSame($lightRules, $this->accentRules("theme:\n  accent:\n    light: '#111111'\n"));
        // dark only
        $this->assertSame($darkRules, $this->accentRules("theme:\n  accent:\n    dark: '#eeeeee'\n"));
        // both
        $this->assertSame([...$lightRules, ...$darkRules], $this->accentRules("theme:\n  accent:\n    light: '#111111'\n    dark: '#eeeeee'\n"));
        // a plain string sets both modes to the same colour
        $this->assertSame([
            ':root[data-theme=light]{--accent:#cc3300}',
            '@media not (prefers-color-scheme:dark){:root:not([data-theme=dark]){--accent:#cc3300}}',
            ':root[data-theme=dark]{--accent:#cc3300}',
            '@media (prefers-color-scheme:dark){:root:not([data-theme=light]){--accent:#cc3300}}',
        ], $this->accentRules("theme:\n  accent: '#cc3300'\n"));
    }

    #[Test]
    public function the_logo_setting_becomes_the_site_icon(): void
    {
        $yaml = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sites/notes/verse.yaml');
        $root = $this->fixtureSite('notes', [
            'verse.yaml' => (string) preg_replace('~^url: .*$~m', 'url: https://example.test/notes/', $yaml)."theme:\n  logo: /icon.svg\n",
            'public/icon.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
        ]);
        $this->buildSite($root);

        $this->assertStringContainsString('<link rel="icon" href="/notes/icon.svg">', $this->html($root, 'index.html'));
        $this->assertFileExists($root.'/dist/icon.svg');
    }

    #[Test]
    public function the_monogram_is_the_xml_escaped_lowercase_first_letter(): void
    {
        $root = $this->fixtureSite('notes', [
            'verse.yaml' => "title: '&Ünal <b>'\nurl: https://example.test/\n",
            'content/articles' => null,
            'content/about.md' => null,
            'content/projects.md' => null,
            'content/legal' => null,
        ]);
        $this->buildSite($root);
        $html = $this->html($root, 'index.html');

        $svg = Html::inlineIcon($html);
        $this->assertStringContainsString('>&amp;</text>', $svg);
        $this->assertNotFalse(simplexml_load_string($svg));
        $this->assertStringContainsString('<title>&amp;Ünal &lt;b&gt;</title>', $html);

        $unicode = $this->fixtureSite('notes', ['verse.yaml' => "title: Ünal\nurl: https://example.test/\n"]);
        $this->buildSite($unicode);
        $this->assertStringContainsString('>ü</text>', Html::inlineIcon($this->html($unicode, 'index.html')));
    }

    #[Test]
    public function absent_optional_data_leaves_no_empty_wrappers(): void
    {
        $root = $this->fixtureSite('notes', [
            'verse.yaml' => "title: Bare\nurl: https://example.test/\n",
            'content/articles' => null,
            'content/about.md' => null,
            'content/projects.md' => null,
            'content/legal' => null,
            'content/plain.md' => "---\ntitle: Plain\n---\nJust text.\n",
            'content/articles/one.md' => "---\ntitle: One\ndate: 2026-03-03\n---\n## Single heading\n\nBody.\n",
            'content/articles/two.md' => "---\ntitle: Two\ndate: 2026-03-02\n---\nBody.\n",
        ]);
        $this->buildSite($root);

        foreach (['index.html', 'articles/index.html', 'plain/index.html', 'articles/one/index.html', 'articles/two/index.html', '404.html'] as $path) {
            $html = $this->html($root, $path);
            foreach (['brand-role', 'footer-right', 'footer-social', 'article-deck', 'timeline-topics', 'article-topic', 'related-article" ', 'class="toc"', 'archive-more', 'og:image', 'name="description"', 'name="robots"', '<style', 'aria-current="page"'] as $absent) {
                if ($absent === 'aria-current="page"' && ($path === 'index.html' || str_starts_with($path, 'articles/'))) {
                    continue; // the Home or Articles menu item is current there
                }
                if ($absent === 'archive-more' && $path === 'index.html') {
                    continue; // the wrapper of the "All articles" link
                }
                if ($absent === 'related-article" ' && $path === 'articles/one/index.html') {
                    continue; // the newer item links to the older one
                }
                $this->assertStringNotContainsString($absent, $html, $path.' must not contain '.$absent);
            }
            $this->assertDoesNotMatchRegularExpression('~<(p|span|ul|nav|header|a|section|aside)[^>]*>\s*</\1>|<div(?![^>]*reading-progress)[^>]*>\s*</div>~', $html, $path);
        }
        $this->assertStringContainsString('<div>© 2026 Bare</div>', $this->html($root, 'index.html'));
        $this->assertStringContainsString('aria-label="Bare — home"', $this->html($root, 'index.html'));
        $this->assertStringContainsString('related-article', $this->html($root, 'articles/one/index.html'));
        $this->assertStringNotContainsString('related-article', $this->html($root, 'articles/two/index.html'));
    }

    #[Test]
    public function standalone_pages_keep_the_route_prefix_rule(): void
    {
        $root = $this->fixtureSite('notes', [
            'content/docs/guide.md' => self::markdown('Guide', ['menu' => 'true', 'order' => '4']),
            'content/docs/extra.md' => self::markdown('Extra', ['permalink' => '/docs/guide/extra/']),
            'content/docs/other.md' => self::markdown('Other'),
        ]);
        $this->buildSite($root);

        $this->assertSame('Guide', self::xpath($this->html($root, 'docs/guide/extra/index.html'))->text('//a[@aria-current="page"]'));
        $this->assertSame('Guide', self::xpath($this->html($root, 'docs/guide/index.html'))->text('//a[@aria-current="page"]'));
        $this->assertSame(0, self::xpath($this->html($root, 'docs/other/index.html'))->count('//a[@aria-current="page"]'));
    }

    #[Test]
    public function the_menu_marks_the_section_each_page_type_belongs_to(): void
    {
        $root = $this->fixtureSite('notes', [
            'content/articles' => null,
            'content/about.md' => self::markdown('About', ['menu' => 'true', 'order' => '-3']),
            'content/articles/one.md' => self::markdown('One', ['date' => '2026-03-01', 'tags' => '[PHP]', 'permalink' => '/elsewhere/one/']),
        ]);
        $this->buildSite($root);

        $nav = fn (string $path): Html => self::xpath($this->html($root, $path));
        // Home, Articles and Tags always come first, whatever the order of the pages
        $this->assertSame(['Home', 'Articles', 'Tags', 'About', 'Projects'], $nav('index.html')->texts('//nav[@class="header-nav"]/a'));
        $this->assertSame('Home', $nav('index.html')->text('//a[@aria-current="page"]'));
        $this->assertSame('Articles', $nav('articles/index.html')->text('//a[@aria-current="page"]'));
        $this->assertSame('Articles', $nav('elsewhere/one/index.html')->text('//a[@aria-current="page"]'));
        $this->assertSame('Tags', $nav('tags/index.html')->text('//a[@aria-current="page"]'));
        $this->assertSame('Tags', $nav('tags/php/index.html')->text('//a[@aria-current="page"]'));
        $this->assertSame(1, $nav('tags/php/index.html')->count('//a[@aria-current="page"]'));
        // a single-article tag archive: singular count, hashed heading, no toolbar, the browsed tag marked current
        $this->assertSame('1 article', $nav('tags/php/index.html')->text('//div[@class="archive-count"]'));
        $this->assertSame('#PHP', $nav('tags/php/index.html')->text('//h1'));
        $this->assertSame(0, $nav('tags/php/index.html')->count('//div[@class="archive-toolbar"]'));
        $this->assertSame(1, $nav('tags/php/index.html')->count('//a[@class="tag-link"][@aria-current="true"][@href="/tags/php/"]'));
        $this->assertSame('About', $nav('about/index.html')->text('//a[@aria-current="page"]'));
        $this->assertSame(0, $nav('404.html')->count('//a[@aria-current="page"]'));
        $this->assertStringNotContainsString('Browse by tag', $this->html($root, 'index.html'));
        $this->assertStringNotContainsString('archive-more"><a', $this->html($root, 'articles/index.html'));
        $this->assertSame('/tags/php/', $nav('elsewhere/one/index.html')->attr('//a[@class="tag-link"]', 'href'));
        $this->assertSame('/articles/', $nav('elsewhere/one/index.html')->attr('//a[contains(@class,"back-link")]', 'href'));
    }

    #[Test]
    public function the_home_page_has_one_screen_reader_heading_and_an_intro_only_with_a_description(): void
    {
        $root = $this->fixtureSite();
        $this->buildSite($root);
        $home = self::xpath($this->html($root, 'index.html'));

        $this->assertSame(1, $home->count('//h1'));
        $this->assertSame('Notes', $home->text('//h1[@class="sr-only"]'));
        $this->assertSame(1, $home->count('//header[@class="page-intro"]/p'));
        $this->assertSame(0, $home->count('//header[@class="page-intro"]/h1'));
        $this->assertSame(0, $home->count('//div[contains(@class,"timeline-page-bare")]'));

        // no description: no intro element at all, and the bare spacing class
        $files = ['verse.yaml' => "title: Bare\nurl: https://example.test/\n", 'content/articles' => null, 'content/about.md' => self::markdown('About')];
        for ($i = 1; $i <= 7; $i++) {
            $files[sprintf('content/articles/more-%02d.md', $i)] = self::markdown('More '.$i, ['date' => sprintf('2026-02-%02d', $i)]);
        }
        $bare = $this->fixtureSite('notes', $files);
        $this->buildSite($bare);
        $page = self::xpath($this->html($bare, 'index.html'));
        $this->assertSame(0, $page->count('//header[@class="page-intro"]'));
        $this->assertSame(1, $page->count('//h1'));
        $this->assertSame('Bare', $page->text('//h1[@class="sr-only"]'));
        $this->assertSame(1, $page->count('//div[contains(@class,"timeline-page-bare")]'));
        $this->assertSame(0, $page->count('//meta[@name="description"]'));
        // the five newest of seven, then the link to the archive
        $this->assertSame(5, $page->count('//article[@class="timeline-item"]'));
        $this->assertSame(['More 7', 'More 6', 'More 5', 'More 4', 'More 3'], $page->texts('//article[@class="timeline-item"]//h2'));
        $this->assertSame(1, $page->count('//div[@class="archive-more"]/a[@class="button-link"][@href="/articles/"][contains(.,"All articles")]'));
        // the archive page has no intro either and is not "bare"
        $archive = self::xpath($this->html($bare, 'articles/index.html'));
        $this->assertSame(0, $archive->count('//meta[@name="description"]'));
        $this->assertSame('Articles', $archive->text('//h1'));
    }

    #[Test]
    public function unpublished_pages_are_marked_in_a_drafts_preview(): void
    {
        $root = $this->fixtureSite('notes', [
            'content/about.md' => self::markdown('About', ['menu' => 'true', 'order' => '3', 'draft' => 'true', 'description' => 'Hidden']),
            'content/later.md' => self::markdown('Later', ['date' => '2030-06-01']),
        ]);
        $this->buildSite($root, drafts: true);
        $out = $root.'/.verse/preview/drafts/';

        $about = Html::parse((string) file_get_contents($out.'about/index.html'));
        $this->assertSame(['Draft'], $about->texts('//header[@class="page-intro"]/p[@class="state-line"]/span[@class="state-badge"]'));
        $this->assertSame(['Draft', 'About', 'Hidden'], $about->texts('//header[@class="page-intro"]/*'));
        $this->assertSame(1, $about->count('//header[@class="page-intro"]/*[1][self::p][@class="state-line"]'));
        $this->assertSame(1, $about->count('//meta[@name="robots"][@content="noindex"]'));
        $this->assertSame(['Scheduled'], Html::parse((string) file_get_contents($out.'later/index.html'))->texts('//header[@class="page-intro"]/p[@class="state-line"]/span[@class="state-badge"]'));

        // the home page is generated and never marked
        $this->assertSame(0, Html::parse((string) file_get_contents($out.'index.html'))->count('//meta[@name="robots"]'));

        // a published page is not marked
        $privacy = Html::parse((string) file_get_contents($out.'legal/privacy/index.html'));
        $this->assertSame([], $privacy->texts('//header[@class="page-intro"]/p'));
        $this->assertSame(0, $privacy->count('//*[@class="state-badge"]'));
    }

    #[Test]
    public function an_off_site_canonical_is_used_and_the_article_end_links_to_the_original(): void
    {
        $root = $this->fixtureSite('notes', [
            'content/articles/cross.md' => self::markdown('Cross-posted', ['date' => '2026-10-18', 'canonical' => 'https://medium.com/@x/cross']),
        ]);
        $this->buildSite($root);
        $html = $this->html($root, 'articles/cross/index.html');
        $xpath = self::xpath($html);

        $this->assertStringContainsString('<link rel="canonical" href="https://medium.com/@x/cross">', $html);
        $this->assertSame('https://medium.com/@x/cross', $xpath->attr('//div[@class="article-end"]/a', 'href'));
        $this->assertSame('_blank', $xpath->attr('//div[@class="article-end"]/a', 'target'));
        $this->assertSame('Read the original', $this->text($xpath, '//div[@class="article-end"]/a'));
        // the next older item is the external fixture item: related links out
        $this->assertStringContainsString('blog.example.test', (string) $xpath->attr('//div[@class="related-article"]/a', 'href'));
        $this->assertSame('_blank', $xpath->attr('//div[@class="related-article"]/a', 'target'));
        $this->assertSame(1, $xpath->count('//div[@class="related-article"]/a/svg/path[@d="M6 18 18 6M6 6h12v12"]'));
        $this->assertSame(0, $xpath->count('//div[@class="related-article"]/a/svg/path[@d="M4 12h15m-6-6 6 6-6 6"]'));
    }

    #[Test]
    public function an_image_that_is_not_in_the_bundle_is_a_content_error(): void
    {
        $root = $this->fixtureSite('notes', [
            'content/articles/trace/page.md' => str_replace('image: diagram.svg', 'image: nope.svg', (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sites/notes/content/articles/trace/page.md')),
        ]);

        try {
            $this->buildSite($root);
            $this->fail('Expected a ContentException');
        } catch (ContentException $exception) {
            $this->assertStringContainsString('content/articles/trace/page.md', $exception->getMessage());
            $this->assertStringContainsString('nope.svg', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($root.'/dist');
    }

    #[Test]
    public function dates_follow_the_site_timezone(): void
    {
        $yaml = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sites/notes/verse.yaml');
        $root = $this->fixtureSite('notes', [
            'verse.yaml' => $yaml."timezone: Europe/Istanbul\n",
            'content/articles/ny.md' => "---\ntitle: New year\ndate: 2026-12-31T22:30:00Z\n---\nText.\n",
        ]);
        $this->buildSite($root, '2027-02-01T00:00:00Z');
        $xpath = self::xpath($this->html($root, 'index.html'));

        $this->assertSame('1 Jan 2027', $this->text($xpath, '//time[@datetime="2027-01-01"]'));
        $this->assertSame(['2027 Jan', '2026 Oct', '2025 Jun'], $xpath->texts('//div[@class="timeline-year"]'));
    }

    #[Test]
    public function a_drafts_preview_shows_unpublished_items_marked_and_not_indexed(): void
    {
        $root = $this->fixtureSite();
        $this->buildSite($root, drafts: true);
        $out = '.verse/preview/drafts/';

        $home = file_get_contents($root.'/'.$out.'index.html');
        $this->assertIsString($home);
        $this->assertStringContainsString('<span class="state-badge">Draft</span>', $home);
        $this->assertStringContainsString('<span class="state-badge">Scheduled</span>', $home);
        $this->assertStringNotContainsString('meta-dot', $home);
        $homeDom = Html::parse($home);
        $this->assertSame(2, $homeDom->count('//div[@class="timeline-meta"]/time/following-sibling::span[@class="state-badge"]'));
        $this->assertStringContainsString('5 articles', (string) file_get_contents($root.'/'.$out.'articles/index.html'));

        $draft = file_get_contents($root.'/'.$out.'articles/draft-notes/index.html');
        $this->assertIsString($draft);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $draft);
        $this->assertStringContainsString('<span class="state-badge">Draft</span>', $draft);
        $draftDom = Html::parse($draft);
        $this->assertSame(1, $draftDom->count('//div[@class="article-meta"]/*[1][@class="state-badge"]'));
        $this->assertSame(1, $draftDom->count('//div[@class="article-meta"]/span[@class="state-badge"]/following-sibling::time'));
        $future = file_get_contents($root.'/'.$out.'articles/future-article/index.html');
        $this->assertIsString($future);
        $this->assertStringContainsString('<span class="state-badge">Scheduled</span>', $future);

        // published pages are not marked
        $article = file_get_contents($root.'/'.$out.'essays/trace/index.html');
        $this->assertIsString($article);
        $this->assertStringNotContainsString('noindex', $article);
        $this->assertStringNotContainsString('state-badge', $article);
        $this->assertDirectoryDoesNotExist($root.'/dist');
    }

    #[Test]
    public function placeholder_and_site_theme_directory_warnings_are_reported(): void
    {
        $root = $this->fixtureSite('notes', [
            'verse.yaml' => "title: Site\nurl: https://example.com/\nauthor: Your Name\nsocial_links:\n  github: https://github.com/your-username\n",
            'themes/mine/theme.yaml' => "name: mine\n",
        ]);
        $result = $this->buildSite($root);

        $this->assertCount(4, $result->warnings);
        $this->assertStringContainsString('example.com', $result->warnings[0]);
        $this->assertStringContainsString('Your Name', $result->warnings[1]);
        $this->assertStringContainsString('your-username', $result->warnings[2]);
        $this->assertStringContainsString('themes/ directory is ignored', $result->warnings[3]);
        $this->assertFileDoesNotExist($root.'/dist/themes/mine/theme.yaml');
    }

    #[Test]
    public function deleted_and_moved_sources_leave_no_stale_output(): void
    {
        $root = $this->fixtureSite();
        $this->buildSite($root);
        $this->assertFileExists($root.'/dist/legal/privacy/index.html');
        $this->assertFileExists($root.'/dist/humans.txt');
        $this->assertFileExists($root.'/dist/essays/trace/diagram.svg');

        unlink($root.'/content/legal/privacy.md');
        unlink($root.'/public/humans.txt');
        $page = $root.'/content/articles/trace/page.md';
        file_put_contents($page, str_replace('/essays/trace/', '/essays/renamed/', (string) file_get_contents($page)));
        $this->buildSite($root);

        $this->assertFileDoesNotExist($root.'/dist/legal/privacy/index.html');
        $this->assertDirectoryDoesNotExist($root.'/dist/legal');
        $this->assertFileDoesNotExist($root.'/dist/humans.txt');
        $this->assertDirectoryDoesNotExist($root.'/dist/essays/trace');
        $this->assertFileExists($root.'/dist/essays/renamed/index.html');
        $this->assertFileExists($root.'/dist/essays/renamed/diagram.svg');
        $this->assertFileExists($root.'/dist/articles/older-note/index.html');
    }
}
