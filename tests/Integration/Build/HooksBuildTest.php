<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Build;

use Astro\Verse\Build\BuildContext;
use Astro\Verse\Build\Builder;
use Astro\Verse\Build\BuildMode;
use Astro\Verse\Config\ConfigLoader;
use Astro\Verse\Console\Application;
use Astro\Verse\Error\ThemeException;
use Astro\Verse\Tests\Support\TestCase;
use Astro\Verse\Theme\ThemeRegistry;
use Astro\Verse\View\Hook;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * `theme/hooks/<hook>.blade.php` of a website, rendered at the default theme's hook points.
 */
final class HooksBuildTest extends TestCase
{
    private string $previousCwd = '';

    protected function setUp(): void
    {
        $this->previousCwd = (string) getcwd();
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        parent::tearDown();
    }

    private static function marker(Hook|string $hook): string
    {
        return '<!--hook:'.($hook instanceof Hook ? $hook->value : $hook).'-->';
    }

    /**
     * Every hook prints its marker; `$overrides` replaces single hook files.
     *
     * @param  array<string, string>  $overrides  hook name => contents
     * @return array<string, string>
     */
    private static function hookFiles(array $overrides = []): array
    {
        $files = [];
        foreach (Hook::cases() as $hook) {
            $files[$hook->path()] = self::marker($hook)."\n";
        }
        foreach ($overrides as $name => $contents) {
            $files['theme/hooks/'.$name.'.blade.php'] = $contents;
        }

        return $files;
    }

    /**
     * @param  array<string, ?string>  $changes
     */
    private function site(array $changes = []): string
    {
        $yaml = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sites/notes/verse.yaml');

        return $this->fixtureSite('notes', $changes + ['verse.yaml' => $yaml."extra:\n  analytics: G-TEST-1\n"]);
    }

    private function html(string $root, string $path): string
    {
        return (string) file_get_contents($root.'/dist/'.$path);
    }

    /**
     * @param  list<string>  $parts  must appear in this order
     */
    private function assertInOrder(string $html, array $parts): void
    {
        $offset = 0;
        foreach ($parts as $part) {
            $position = strpos($html, $part, $offset);
            $this->assertNotFalse($position, 'Expected "'.$part.'" after offset '.$offset.' in: '.$html);
            $offset = $position + strlen($part);
        }
    }

    private function assertNoMarkers(string $html, Hook ...$hooks): void
    {
        foreach ($hooks as $hook) {
            $this->assertStringNotContainsString(self::marker($hook), $html);
        }
    }

    private function runCommand(string $command, string $root): ApplicationTester
    {
        chdir($root);
        $application = new Application;
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => $command], ['capture_stderr_separately' => true]);

        return $tester;
    }

    #[Test]
    public function an_article_with_a_table_of_contents_gets_all_but_the_page_hooks(): void
    {
        $root = $this->site(self::hookFiles());
        $this->buildSite($root);

        $html = $this->html($root, 'essays/trace/index.html');
        $this->assertStringContainsString('class="mobile-toc"', $html);
        $this->assertInOrder($html, [
            '<head>', '<link rel="icon"', self::marker(Hook::Head), '</head>',
            '<body>', self::marker(Hook::BodyStart), 'class="skip-link"',
            '<main ', self::marker(Hook::MainStart), 'class="article-page"',
            '<details class="mobile-toc">', '</details>', self::marker(Hook::ArticleBefore), '<article class="prose"',
            '</article>', self::marker(Hook::ArticleAfter), '<div class="article-end">',
            self::marker(Hook::MainEnd), '</main>',
            '<footer', '</footer>', self::marker(Hook::BodyEnd), '</body>',
        ]);
        $this->assertNoMarkers($html, Hook::PageBefore, Hook::PageAfter);
    }

    #[Test]
    public function an_article_without_a_table_of_contents_gets_the_article_hooks_too(): void
    {
        $root = $this->site(self::hookFiles());
        $this->buildSite($root);

        $html = $this->html($root, 'articles/older-note/index.html');
        $this->assertStringNotContainsString('class="mobile-toc"', $html);
        $this->assertInOrder($html, [
            'class="article-layout"', self::marker(Hook::ArticleBefore), '<article class="prose"', '</article>',
            self::marker(Hook::ArticleAfter), '<div class="article-end">',
        ]);
    }

    #[Test]
    public function a_standalone_page_gets_the_page_hooks(): void
    {
        $root = $this->site(self::hookFiles());
        $this->buildSite($root);

        $html = $this->html($root, 'about/index.html');
        $this->assertInOrder($html, [
            self::marker(Hook::MainStart), '<div class="article-layout"><div>', self::marker(Hook::PageBefore),
            '<article class="prose">', '</article>', self::marker(Hook::PageAfter), '</div></div>', self::marker(Hook::MainEnd),
        ]);
        $this->assertNoMarkers($html, Hook::ArticleBefore, Hook::ArticleAfter);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherRoles(): array
    {
        return [
            'home' => ['index.html'],
            'listing' => ['articles/index.html'],
            'tag index' => ['tags/index.html'],
            'tag archive' => ['tags/laravel/index.html'],
            'not found' => ['404.html'],
        ];
    }

    #[DataProvider('otherRoles')]
    #[Test]
    public function the_layout_hooks_appear_once_on_every_other_role_and_the_content_hooks_do_not(string $path): void
    {
        $root = $this->site(self::hookFiles());
        $this->buildSite($root);

        $html = $this->html($root, $path);
        $this->assertInOrder($html, [
            self::marker(Hook::Head), '</head>', self::marker(Hook::BodyStart), self::marker(Hook::MainStart),
            self::marker(Hook::MainEnd), '</main>', self::marker(Hook::BodyEnd), '</body>',
        ]);
        foreach ([Hook::Head, Hook::BodyStart, Hook::MainStart, Hook::MainEnd, Hook::BodyEnd] as $hook) {
            $this->assertSame(1, substr_count($html, self::marker($hook)), $hook->value);
        }
        $this->assertNoMarkers($html, Hook::ArticleBefore, Hook::ArticleAfter, Hook::PageBefore, Hook::PageAfter);
    }

    #[Test]
    public function paginated_listings_and_tag_archives_get_the_layout_hooks_on_every_page(): void
    {
        $files = ['verse.yaml' => self::SITE_YAML, 'content/about.md' => self::markdown('About')];
        for ($i = 1; $i <= 12; $i++) {
            $files['content/articles/n'.$i.'/page.md'] = self::markdown('Note '.$i, ['date' => '2026-01-'.sprintf('%02d', $i), 'tags' => '[Many]']);
        }
        $root = $this->makeSite($files + self::hookFiles());
        $this->buildSite($root);

        foreach (['articles/page/2/index.html', 'tags/many/page/2/index.html'] as $path) {
            $html = $this->html($root, $path);
            $this->assertInOrder($html, [self::marker(Hook::Head), self::marker(Hook::BodyStart), self::marker(Hook::MainStart), self::marker(Hook::MainEnd), self::marker(Hook::BodyEnd)]);
            $this->assertNoMarkers($html, Hook::ArticleBefore, Hook::PageBefore);
        }
    }

    #[Test]
    public function hooks_see_the_including_views_data_and_the_helper(): void
    {
        $head = <<<'BLADE'
            @isset($article)
            <meta name="x-article" content="{{ $article->title }}">
            @endisset
            <meta name="x-layout" content="{{ $layout->title }}">
            <meta name="x-site" content="{{ $site->extra['analytics'] }}">
            <meta name="x-home" content="{{ verse()->url('/') }}">
            BLADE;
        $pageBefore = "<p>{{ \$page->meta->title }} / {{ \$theme->toc ? 'toc' : 'no-toc' }} / {{ count(\$menu->items) }}</p>\n";
        $root = $this->site(self::hookFiles(['head' => $head."\n", 'page-before' => $pageBefore]));
        $this->buildSite($root);

        $article = $this->html($root, 'essays/trace/index.html');
        $this->assertStringContainsString('<meta name="x-article" content="', $article);
        $this->assertStringContainsString('<meta name="x-site" content="G-TEST-1">', $article);
        $this->assertStringContainsString('<meta name="x-home" content="/">', $article);

        $home = $this->html($root, 'index.html');
        $this->assertStringNotContainsString('x-article', $home);
        $this->assertStringContainsString('<meta name="x-site" content="G-TEST-1">', $home);

        $about = $this->html($root, 'about/index.html');
        $this->assertStringContainsString('<p>About / toc / ', $about);
        $this->assertStringNotContainsString('x-article', $about);

        // rendered after every article: an article's data does not leak into later pages
        foreach (['tags/index.html', '404.html'] as $path) {
            $this->assertStringNotContainsString('x-article', $this->html($root, $path));
        }
    }

    #[Test]
    public function a_hook_cannot_place_another_hook(): void
    {
        $root = $this->site(self::hookFiles(['head' => "@verseHook('head')\n"]));

        try {
            $this->buildSite($root);
            $this->fail('Expected a ThemeException');
        } catch (ThemeException $exception) {
            $this->assertSame('theme/hooks/head.blade.php', $exception->sourceFile);
            $this->assertStringContainsString('a hook cannot place another hook', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($root.'/dist');
    }

    #[Test]
    public function hooks_escape_double_braces_and_print_raw_html_with_bang_braces(): void
    {
        $root = $this->site(self::hookFiles(['head' => "{{ '<b>escaped</b>' }}{!! '<i>raw</i>' !!}\n"]));
        $this->buildSite($root);

        $html = $this->html($root, 'index.html');
        $this->assertStringContainsString('&lt;b&gt;escaped&lt;/b&gt;<i>raw</i>', $html);
    }

    #[Test]
    public function is_preview_is_false_in_a_production_build(): void
    {
        $root = $this->site(self::hookFiles(['head' => "@if (verse()->isPreview())preview @else production @endif\n"]));
        $this->buildSite($root);

        $this->assertStringContainsString('production ', $this->html($root, 'index.html'));
        $this->assertStringNotContainsString('preview ', $this->html($root, 'index.html'));
    }

    #[DataProvider('previewModes')]
    #[Test]
    public function is_preview_is_true_in_a_preview_build_with_or_without_drafts(bool $drafts): void
    {
        $root = $this->site(self::hookFiles(['head' => "@if (verse()->isPreview())preview @else production @endif\n"]));
        $themes = ThemeRegistry::bundled();
        $context = BuildContext::create(
            $root,
            (new ConfigLoader($themes))->load($root, requireUrl: true),
            BuildMode::Preview,
            $drafts,
            new DateTimeImmutable('2026-10-20T12:00:00Z', new DateTimeZone('UTC')),
            $root.'/.verse/preview/site',
        );
        (new Builder($themes))->build($context);

        $html = (string) file_get_contents($root.'/.verse/preview/site/index.html');
        $this->assertStringContainsString('preview ', $html);
        $this->assertStringNotContainsString('production ', $html);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function previewModes(): array
    {
        return ['with drafts' => [true], 'without drafts' => [false]];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function failingHooks(): array
    {
        return [
            'a PHP parse error' => ["@php \$x = ; @endphp\n", 'syntax error'],
            'an exception thrown by the hook' => ["@php throw new \\RuntimeException('boom'); @endphp\n", 'boom'],
            'an undefined variable' => ["{{ \$nope }}\n", 'Undefined variable $nope'],
            'an undefined array key' => ["{{ \$site->extra['missing'] }}\n", 'Undefined array key "missing"'],
        ];
    }

    #[DataProvider('failingHooks')]
    #[Test]
    public function a_failing_hook_fails_the_build_naming_the_hook_file_and_publishes_nothing(string $source, string $detail): void
    {
        $root = $this->site(['theme/hooks/head.blade.php' => $source]);

        try {
            $this->buildSite($root);
            $this->fail('Expected a ThemeException');
        } catch (ThemeException $exception) {
            $this->assertSame('theme/hooks/head.blade.php', $exception->sourceFile);
            $this->assertStringStartsWith('theme error in theme/hooks/head.blade.php: the hook failed to render: ', $exception->getMessage());
            $this->assertStringContainsString($detail, $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($root.'/dist');
    }

    #[Test]
    public function a_theme_helper_failing_inside_a_hook_keeps_its_own_error(): void
    {
        $root = $this->site(['theme/hooks/head.blade.php' => "{!! verse()->icon('nope') !!}\n"]);

        try {
            $this->buildSite($root);
            $this->fail('Expected a ThemeException');
        } catch (ThemeException $exception) {
            $this->assertStringContainsString('unknown icon "nope"', $exception->getMessage());
            $this->assertStringNotContainsString('the hook failed to render', $exception->getMessage());
        }
    }

    #[Test]
    public function a_failing_hook_leaves_the_previous_output_and_fixing_it_builds_again(): void
    {
        $root = $this->site(self::hookFiles());
        $this->buildSite($root);
        $before = self::snapshot($root.'/dist');

        file_put_contents($root.'/theme/hooks/body-end.blade.php', "{{ \$nope }}\n");
        try {
            $this->buildSite($root);
            $this->fail('Expected a ThemeException');
        } catch (ThemeException $exception) {
            $this->assertSame('theme/hooks/body-end.blade.php', $exception->sourceFile);
        }
        $this->assertSame($before, self::snapshot($root.'/dist'));
        $this->assertSame([], glob($root.'/.verse/staging/*') ?: []);

        file_put_contents($root.'/theme/hooks/body-end.blade.php', "<!--fixed-->\n");
        $this->buildSite($root);
        $this->assertStringContainsString('<!--fixed-->', $this->html($root, 'index.html'));
    }

    #[Test]
    public function check_and_build_exit_one_naming_the_failing_hook_file(): void
    {
        $root = $this->site(['theme/hooks/head.blade.php' => "{{ \$nope }}\n"]);

        foreach (['check', 'build'] as $command) {
            $tester = $this->runCommand($command, $root);

            $this->assertSame(1, $tester->getStatusCode(), $command);
            $this->assertStringContainsString('error: theme error in theme/hooks/head.blade.php: the hook failed to render: ', $tester->getErrorOutput());
            $this->assertStringNotContainsString('#0', $tester->getErrorOutput());
        }
    }

    #[Test]
    public function an_invalid_theme_folder_fails_before_anything_is_written(): void
    {
        $root = $this->site(['theme/views/x.blade.php' => '']);

        $tester = $this->runCommand('build', $root);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('error: theme error in theme/views: only theme/hooks/ is supported in this version', $tester->getErrorOutput());
        $this->assertDirectoryDoesNotExist($root.'/dist');
        $this->assertDirectoryDoesNotExist($root.'/.verse/cache');
    }

    #[Test]
    public function an_empty_hooks_folder_or_an_empty_hook_file_changes_nothing(): void
    {
        $plain = $this->site();
        $this->buildSite($plain);

        $folder = $this->site();
        mkdir($folder.'/theme/hooks', 0775, true);
        $this->buildSite($folder);

        $empty = $this->site(['theme/hooks/head.blade.php' => '', 'theme/hooks/main-end.blade.php' => '']);
        $this->buildSite($empty);

        // the marker file carries a per-build id
        $expected = array_diff_key(self::snapshot($plain.'/dist'), ['.verse-output' => true]);
        $this->assertSame($expected, array_diff_key(self::snapshot($folder.'/dist'), ['.verse-output' => true]));
        $this->assertSame($expected, array_diff_key(self::snapshot($empty.'/dist'), ['.verse-output' => true]));
    }

    #[Test]
    public function a_hook_change_changes_the_output_on_the_next_build(): void
    {
        $root = $this->site(['theme/hooks/head.blade.php' => "<!--one-->\n"]);
        $this->buildSite($root);
        $this->assertStringContainsString('<!--one-->', $this->html($root, 'index.html'));

        file_put_contents($root.'/theme/hooks/head.blade.php', "<!--two-->\n");
        $this->buildSite($root);
        $this->assertStringContainsString('<!--two-->', $this->html($root, 'index.html'));
        $this->assertStringNotContainsString('<!--one-->', $this->html($root, 'index.html'));
    }

    #[Test]
    public function the_default_theme_places_every_hook_exactly_once(): void
    {
        $views = '';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3).'/themes/default/views', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $views .= (string) file_get_contents((string) $file->getPathname());
        }

        foreach (Hook::cases() as $hook) {
            $this->assertSame(1, substr_count($views, "@verseHook('".$hook->value."')"), $hook->value);
        }
        $this->assertSame(count(Hook::cases()), substr_count($views, '@verseHook('));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badDirectives(): array
    {
        return [
            'an unknown hook' => ["@verseHook('nope')", 'unknown hook "nope" in @verseHook. Known hooks: head, body-start,'],
            'a variable' => ['@verseHook($name)', '@verseHook takes a hook name in quotes'],
            'a concatenation' => ["@verseHook('he'.'ad')", '@verseHook takes a hook name in quotes'],
            'no argument' => ['@verseHook()', '@verseHook takes a hook name in quotes'],
        ];
    }

    #[DataProvider('badDirectives')]
    #[Test]
    public function a_bad_directive_in_a_theme_view_fails_naming_the_view(string $directive, string $detail): void
    {
        $files = ThemeSelectionTest::paperWith(['paper/views/layouts/base.blade.php' => "<!doctype html><html><body>{$directive}@yield('content')</body></html>"]);
        $root = $this->fixtureSite('notes', [
            'verse.yaml' => (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sites/notes/verse.yaml')."theme:\n  name: paper\n",
        ]);

        try {
            $this->buildSite($root, themes: $this->themeRegistry($files));
            $this->fail('Expected a ThemeException');
        } catch (ThemeException $exception) {
            $this->assertSame('themes/paper/views/layouts/base.blade.php', $exception->sourceFile);
            $this->assertStringContainsString($detail, $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($root.'/dist');
    }
}
