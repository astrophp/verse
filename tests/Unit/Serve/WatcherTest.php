<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Serve;

use Astro\Verse\Serve\RebuildScheduler;
use Astro\Verse\Serve\Watcher;
use Astro\Verse\Tests\Support\TestCase;
use Astro\Verse\Theme\ThemeRegistry;
use Astro\Verse\Verse;
use PHPUnit\Framework\Attributes\Test;

final class WatcherTest extends TestCase
{
    private const string THEME_YAML = "name: paper\n";

    private function site(): string
    {
        return $this->makeSite([
            'verse.yaml' => self::SITE_YAML,
            'content/about.md' => "---\ntitle: About\n---\nHello\n",
            'content/articles/post.md' => "---\ntitle: Post\n---\nBody\n",
            'public/robots.txt' => 'User-agent: *',
        ]);
    }

    private function watcher(string $root): Watcher
    {
        $watcher = new Watcher($root);
        $this->assertFalse($watcher->poll(), 'the first poll only records a baseline');

        return $watcher;
    }

    #[Test]
    public function an_unchanged_site_reports_nothing(): void
    {
        $watcher = $this->watcher($this->site());

        $this->assertFalse($watcher->poll());
        $this->assertFalse($watcher->poll());
        $this->assertSame([], $watcher->changes());
    }

    #[Test]
    public function it_detects_a_created_file(): void
    {
        $root = $this->site();
        $watcher = $this->watcher($root);

        file_put_contents($root.'/content/articles/new.md', "---\ntitle: New\n---\n");

        $this->assertTrue($watcher->poll());
        $this->assertSame(['content/articles/new.md'], $watcher->changes());
        $this->assertFalse($watcher->poll(), 'a change is reported once');
    }

    #[Test]
    public function it_detects_an_edit(): void
    {
        $root = $this->site();
        $watcher = $this->watcher($root);

        file_put_contents($root.'/content/about.md', "---\ntitle: About\n---\nHello, world\n");
        clearstatcache();

        $this->assertTrue($watcher->poll());
        $this->assertSame(['content/about.md'], $watcher->changes());
    }

    #[Test]
    public function it_detects_an_edit_that_keeps_size_and_mtime(): void
    {
        $root = $this->site();
        $path = $root.'/content/about.md';
        $mtime = (int) filemtime($path);
        $watcher = $this->watcher($root);

        file_put_contents($path, "---\ntitle: About\n---\nHELLO\n");
        touch($path, $mtime);

        $this->assertSame(strlen("---\ntitle: About\n---\nHello\n"), filesize($path));
        $this->assertTrue($watcher->poll());
        $this->assertSame(['content/about.md'], $watcher->changes());
    }

    #[Test]
    public function it_detects_a_rename(): void
    {
        $root = $this->site();
        $watcher = $this->watcher($root);

        rename($root.'/content/articles/post.md', $root.'/content/articles/renamed.md');

        $this->assertTrue($watcher->poll());
        $this->assertSame(['content/articles/post.md', 'content/articles/renamed.md'], $watcher->changes());
    }

    #[Test]
    public function it_detects_a_delete_and_a_removed_directory(): void
    {
        $root = $this->site();
        $watcher = $this->watcher($root);

        unlink($root.'/public/robots.txt');
        $this->assertTrue($watcher->poll());
        $this->assertSame(['public/robots.txt'], $watcher->changes());

        self::removeTree($root.'/content/articles');
        $this->assertTrue($watcher->poll());
        $this->assertSame(['content/articles/', 'content/articles/post.md'], $watcher->changes());
    }

    #[Test]
    public function it_detects_a_deleted_or_created_empty_directory(): void
    {
        $root = $this->site();
        mkdir($root.'/content/articles/empty');
        $watcher = $this->watcher($root);

        rmdir($root.'/content/articles/empty');
        $this->assertTrue($watcher->poll());
        $this->assertSame(['content/articles/empty/'], $watcher->changes());

        mkdir($root.'/content/articles/empty');
        $this->assertTrue($watcher->poll());
        $this->assertSame(['content/articles/empty/'], $watcher->changes());
        $this->assertFalse($watcher->poll());
    }

    #[Test]
    public function excluded_directories_stay_excluded(): void
    {
        $root = $this->site();
        $watcher = $this->watcher($root);

        $this->writeFiles($root, ['content/node_modules/x/y.js' => 'x']);
        mkdir($root.'/content/.cache');
        mkdir($root.'/content/vendor/sub', 0775, true);

        $this->assertFalse($watcher->poll());
    }

    #[Test]
    public function it_watches_verse_yaml(): void
    {
        $root = $this->site();
        $watcher = $this->watcher($root);

        file_put_contents($root.'/verse.yaml', self::SITE_YAML."tagline: new\n");

        $this->assertTrue($watcher->poll());
        $this->assertSame(['verse.yaml'], $watcher->changes());
    }

    #[Test]
    public function it_sees_binary_files_by_size_and_mtime(): void
    {
        $root = $this->makeSite(['verse.yaml' => self::SITE_YAML, 'content/about.md' => "---\ntitle: H\n---\n", 'public/logo.png' => 'AAAA']);
        $watcher = $this->watcher($root);

        file_put_contents($root.'/public/logo.png', 'AAAAB');

        $this->assertTrue($watcher->poll());
        $this->assertSame(['public/logo.png'], $watcher->changes());
    }

    #[Test]
    public function it_ignores_state_output_dependency_and_hidden_entries(): void
    {
        $root = $this->site();
        $watcher = $this->watcher($root);

        $this->writeFiles($root, [
            '.verse/preview/status.json' => '{}',
            'dist/index.html' => 'x',
            'node_modules/x/index.js' => 'x',
            'vendor/autoload.php' => 'x',
            'content/.DS_Store' => 'x',
            'content/.hidden/note.md' => 'x',
            'content/node_modules/pkg/index.js' => 'x',
            'content/vendor/lib.php' => 'x',
            'public/.env' => 'x',
            'public/.git/HEAD' => 'x',
            'README.md' => 'x',
            'themes/ignored.txt' => 'x',
        ]);

        $this->assertFalse($watcher->poll());
        $this->assertSame([], $watcher->changes());
    }

    #[Test]
    public function it_watches_public_well_known_but_no_other_dot_directory(): void
    {
        $root = $this->site();
        $watcher = $this->watcher($root);

        file_put_contents($root.'/public/.hidden', 'x');
        $this->assertFalse($watcher->poll());

        $this->writeFiles($root, ['public/.well-known/security.txt' => 'Contact: a']);
        $this->assertTrue($watcher->poll());
        $this->assertSame(['public/.well-known/', 'public/.well-known/security.txt'], $watcher->changes());
    }

    #[Test]
    public function it_watches_the_default_theme_root_and_its_vite_manifest(): void
    {
        $watcher = $this->watcher($this->site());

        $this->assertStringEndsWith('/themes/default', $watcher->themeRoot());
        $this->assertFileExists($watcher->themeRoot().'/dist/.vite/manifest.json');
    }

    #[Test]
    public function it_watches_the_selected_theme_except_node_modules_and_dot_entries(): void
    {
        $themes = $this->themeRegistry([
            'paper/theme.yaml' => self::THEME_YAML,
            'paper/views/page.blade.php' => 'page',
            'paper/dist/.vite/manifest.json' => '{}',
        ]);
        $themeRoot = $themes->directoryOf('paper');
        $this->assertNotNull($themeRoot);
        $root = $this->makeSite(['verse.yaml' => self::SITE_YAML."theme:\n  name: paper\n", 'content/about.md' => "---\ntitle: H\n---\n"]);
        $watcher = new Watcher($root, $themes);
        $this->assertFalse($watcher->poll());
        $this->assertSame($themeRoot, $watcher->themeRoot());

        $this->writeFiles($themeRoot, ['node_modules/pkg/index.js' => 'x', '.cache/a.js' => 'x', 'dist/.hidden' => 'x']);
        $this->assertFalse($watcher->poll());

        file_put_contents($themeRoot.'/views/page.blade.php', 'changed page');
        $this->assertTrue($watcher->poll());
        $this->assertSame(['theme:views/page.blade.php'], $watcher->changes());

        file_put_contents($themeRoot.'/dist/.vite/manifest.json', '{"a":1}');
        $this->assertTrue($watcher->poll());
        $this->assertSame(['theme:dist/.vite/manifest.json'], $watcher->changes());
    }

    #[Test]
    public function changing_the_theme_in_verse_yaml_switches_the_watched_root(): void
    {
        $themes = $this->themeRegistry([
            'default/theme.yaml' => "name: default\n",
            'default/views/a.blade.php' => 'a',
            'paper/theme.yaml' => self::THEME_YAML,
            'paper/views/b.blade.php' => 'b',
        ]);
        $root = $this->makeSite(['verse.yaml' => self::SITE_YAML, 'content/about.md' => "---\ntitle: H\n---\n"]);
        $watcher = new Watcher($root, $themes);
        $watcher->poll();
        $this->assertSame($themes->directoryOf('default'), $watcher->themeRoot());

        file_put_contents($root.'/verse.yaml', self::SITE_YAML."theme:\n  name: paper\n");
        $this->assertTrue($watcher->poll());
        $this->assertSame($themes->directoryOf('paper'), $watcher->themeRoot());

        $watcher->refreshTheme();
        $this->assertFalse($watcher->poll(), 'the switch was already reported by the poll that saw verse.yaml change');

        file_put_contents((string) $themes->directoryOf('paper').'/views/b.blade.php', 'b2');
        $this->assertTrue($watcher->poll());
    }

    #[Test]
    public function a_broken_verse_yaml_falls_back_to_the_default_theme_and_recovers(): void
    {
        $root = $this->site();
        $watcher = $this->watcher($root);

        file_put_contents($root.'/verse.yaml', "title: [unclosed\ntheme: {name: nope\n");
        $this->assertTrue($watcher->poll());
        $this->assertStringEndsWith('/themes/default', $watcher->themeRoot());

        file_put_contents($root.'/verse.yaml', self::SITE_YAML);
        $this->assertTrue($watcher->poll());
        $this->assertSame(['verse.yaml'], $watcher->changes());
    }

    #[Test]
    public function an_invalid_theme_name_falls_back_to_the_default_theme(): void
    {
        $root = $this->makeSite(['verse.yaml' => self::SITE_YAML."theme:\n  name: Not Valid!\n"]);
        $watcher = $this->watcher($root);

        $this->assertSame(ThemeRegistry::bundled()->directoryOf('default'), $watcher->themeRoot());
    }

    #[Test]
    public function an_unknown_theme_name_watches_where_that_theme_would_live(): void
    {
        $root = $this->makeSite(['verse.yaml' => self::SITE_YAML."theme:\n  name: missing\n"]);
        $watcher = $this->watcher($root);

        $this->assertSame(Verse::themesPath().'/missing', $watcher->themeRoot());
    }

    #[Test]
    public function deleting_and_restoring_the_selected_themes_theme_yaml_is_seen(): void
    {
        $themes = $this->themeRegistry(['paper/theme.yaml' => self::THEME_YAML, 'paper/views/page.blade.php' => 'page']);
        $themeRoot = $themes->directoryOf('paper');
        $this->assertNotNull($themeRoot);
        $root = $this->makeSite(['verse.yaml' => self::SITE_YAML."theme:\n  name: paper\n", 'content/about.md' => "---\ntitle: H\n---\n"]);
        $watcher = new Watcher($root, $themes);
        $watcher->poll();
        $scheduler = new RebuildScheduler;

        unlink($themeRoot.'/theme.yaml');
        $this->assertTrue($watcher->poll());
        $this->assertSame(['theme:theme.yaml'], $watcher->changes());
        $scheduler->changed(0.0);
        $this->assertTrue($scheduler->tick(1.0));
        $watcher->refreshTheme();
        $scheduler->finished();
        $this->assertSame($themeRoot, $watcher->themeRoot(), 'the missing theme.yaml does not move the watch to a placeholder');

        file_put_contents($themeRoot.'/views/page.blade.php', 'edited while broken');
        $this->assertTrue($watcher->poll());

        file_put_contents($themeRoot.'/theme.yaml', self::THEME_YAML);
        $this->assertTrue($watcher->poll());
        $this->assertSame(['theme:theme.yaml'], $watcher->changes());
        $scheduler->changed(2.0);
        $this->assertTrue($scheduler->tick(3.0), 'the rebuild is scheduled');

        self::removeTree($themeRoot);
        $this->assertTrue($watcher->poll());
        $watcher->refreshTheme();
        $this->assertSame($themeRoot, $watcher->themeRoot());
        $this->writeFiles($themeRoot, ['theme.yaml' => self::THEME_YAML]);
        $this->assertTrue($watcher->poll(), 'a restored theme folder is seen');
    }

    #[Test]
    public function refreshing_the_theme_does_not_swallow_changes_made_while_a_build_ran(): void
    {
        $themes = $this->themeRegistry([
            'default/theme.yaml' => "name: default\n",
            'paper/theme.yaml' => self::THEME_YAML,
            'paper/views/b.blade.php' => 'b',
        ]);
        $root = $this->makeSite(['verse.yaml' => self::SITE_YAML, 'content/about.md' => "---\ntitle: H\n---\nHello\n"]);
        $watcher = new Watcher($root, $themes);
        $watcher->poll();
        $scheduler = new RebuildScheduler;

        // a worker is running; meanwhile the theme is switched and a page is edited
        $scheduler->changed(0.0);
        $this->assertTrue($scheduler->tick(1.0));
        file_put_contents($root.'/verse.yaml', self::SITE_YAML."theme:\n  name: paper\n");
        file_put_contents($root.'/content/about.md', "---\ntitle: H\n---\nEdited\n");

        $watcher->refreshTheme();
        $scheduler->finished();

        $this->assertTrue($watcher->poll());
        $this->assertContains('content/about.md', $watcher->changes());
        $this->assertContains('verse.yaml', $watcher->changes());
        $scheduler->changed(1.5);
        $this->assertTrue($scheduler->tick(2.0), 'a follow-up build is scheduled');
        $this->assertSame($themes->directoryOf('paper'), $watcher->themeRoot());
    }

    #[Test]
    public function symbolic_links_are_recorded_but_not_followed(): void
    {
        $root = $this->site();
        $outside = $this->makeSite(['a.md' => 'x']);
        symlink($outside, $root.'/content/link');
        $watcher = $this->watcher($root);

        file_put_contents($outside.'/b.md', 'new');
        $this->assertFalse($watcher->poll(), 'files behind a link are not scanned');

        unlink($root.'/content/link');
        symlink($outside.'/a.md', $root.'/content/link');
        $this->assertTrue($watcher->poll());
    }
}
