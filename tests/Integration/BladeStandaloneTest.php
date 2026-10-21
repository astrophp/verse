<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration;

use Astro\Verse\View\BladeFactory;
use Illuminate\View\Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Standalone Blade (no Laravel application) renders layouts, sections,
 * anonymous components, a custom directive and shared data from an unrelated
 * working directory, twice in one process.
 */
final class BladeStandaloneTest extends TestCase
{
    private string $previousCwd = '';

    private string $workDir = '';

    protected function setUp(): void
    {
        $this->previousCwd = (string) getcwd();
        $this->workDir = sys_get_temp_dir().'/verse-blade-'.bin2hex(random_bytes(6));
        mkdir($this->workDir, 0775, true);
        chdir($this->workDir);
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        $this->removeTree($this->workDir);
    }

    #[Test]
    public function it_renders_layout_sections_and_components_from_an_unrelated_directory(): void
    {
        $html = $this->render($this->factory('cache-a', 'A'));

        $this->assertStringContainsString('<title>Page A - Fixture</title>', $html);
        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/theme/app-A.css">', $html);
        $this->assertStringContainsString('<script type="module" src="/assets/theme/app-A.js"></script>', $html);
        $this->assertStringContainsString('<footer>Custom footer</footer>', str_replace("\n", '', $html));
    }

    #[Test]
    public function it_applies_props_slots_attributes_and_nested_components(): void
    {
        $html = $this->render($this->factory('cache-a', 'A'));

        // props + escaping of the prop value
        $this->assertStringContainsString('<h2>Escaped &amp;amp; &lt;b&gt;</h2>', $html);
        // $attributes->merge(): class merged, extra attribute passed through
        $this->assertStringContainsString('<section class="card extra" data-id="7">', $html);
        // default slot, escaped {{ }} versus raw {!! !!}
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('<em>trusted</em>', $html);
        // named slot
        $this->assertStringContainsString('<footer class="card-footer">Footer slot</footer>', $html);
        // nested component: panel uses card with a computed prop and its own class
        $this->assertStringContainsString('<div class="panel">', $html);
        $this->assertStringContainsString('<section class="card inner">', $html);
        $this->assertStringContainsString('<h3>Nested</h3>', $html);
        $this->assertStringContainsString('inside panel', $html);
        $this->assertStringContainsString('<footer class="card-footer">nested footer</footer>', $html);
    }

    #[Test]
    public function it_renders_again_with_a_second_factory_and_cache_directory_in_one_process(): void
    {
        $first = $this->render($this->factory('cache-a', 'A'));
        $second = $this->render($this->factory('cache-b', 'B'));
        $again = $this->render($this->factory('cache-a', 'A'));

        $this->assertStringContainsString('app-A.css', $first);
        $this->assertStringNotContainsString('app-B.css', $first);
        $this->assertStringContainsString('app-B.css', $second);
        $this->assertStringNotContainsString('app-A.css', $second);
        $this->assertSame($first, $again);

        $this->assertNotSame([], glob($this->workDir.'/cache-a/*.php'));
        $this->assertNotSame([], glob($this->workDir.'/cache-b/*.php'));
    }

    private function factory(string $cacheDir, string $tag): Factory
    {
        $factory = BladeFactory::create(__DIR__.'/../Fixtures/views', $this->workDir.'/'.$cacheDir);
        $factory->share('__verse', new FixtureThemeContext($tag));

        return $factory;
    }

    private function render(Factory $factory): string
    {
        $tag = $factory->shared('__verse');
        $this->assertInstanceOf(FixtureThemeContext::class, $tag);

        return $factory->make('verse::page', [
            'title' => 'Page '.$tag->tag,
            'untrusted' => '<script>alert(1)</script>',
            'trusted' => '<em>trusted</em>',
        ])->render();
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            is_dir($child) && ! is_link($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
