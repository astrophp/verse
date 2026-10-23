<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration;

use Astro\Verse\Assets\ViteManifest;
use Astro\Verse\Error\ThemeException;
use Astro\Verse\Tests\Support\TestCase;
use Astro\Verse\Theme\ThemeRegistry;
use Astro\Verse\View\BladeFactory;
use Astro\Verse\View\ThemeContext;
use Illuminate\Container\Container;
use Illuminate\View\Factory;
use PHPUnit\Framework\Attributes\Test;

/**
 * Standalone Blade (no Laravel application) renders layouts, sections,
 * anonymous components, a custom directive and the `verse()` helper from an
 * unrelated working directory, twice in one process.
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
        self::removeTree($this->workDir);
        parent::tearDown();
    }

    #[Test]
    public function it_renders_layout_sections_and_components_from_an_unrelated_directory(): void
    {
        $html = $this->render($this->factory('cache-a', 'a'), 'a');

        $this->assertStringContainsString('<title>Page a - Fixture</title>', $html);
        $this->assertStringContainsString('<link rel="stylesheet" href="/a/assets/theme/app-', $html);
        $this->assertStringContainsString('<script type="module" src="/a/assets/theme/app-', $html);
        $this->assertStringContainsString('<footer>Custom footer</footer>', str_replace("\n", '', $html));
    }

    #[Test]
    public function it_applies_props_slots_attributes_and_nested_components(): void
    {
        $html = $this->render($this->factory('cache-a', 'a'), 'a');

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
        $first = $this->render($this->factory('cache-a', 'a'), 'a');
        $second = $this->render($this->factory('cache-b', 'b'), 'b');
        $again = $this->render($this->factory('cache-a', 'a'), 'a');

        $this->assertStringContainsString('/a/assets/theme/', $first);
        $this->assertStringNotContainsString('/b/assets/theme/', $first);
        $this->assertStringContainsString('/b/assets/theme/', $second);
        $this->assertStringNotContainsString('/a/assets/theme/', $second);
        $this->assertSame($first, $again);

        $this->assertNotSame([], glob($this->workDir.'/cache-a/*.php'));
        $this->assertNotSame([], glob($this->workDir.'/cache-b/*.php'));
    }

    #[Test]
    public function verse_outside_a_render_names_the_problem(): void
    {
        Container::setInstance(new Container);

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('verse() is only available while Verse renders a theme view.');

        verse();
    }

    /**
     * A factory whose `verse()` is a real theme context for a site under the base path `/<base>/`.
     */
    private function factory(string $cacheDir, string $base): Factory
    {
        $factory = BladeFactory::create(__DIR__.'/../Fixtures/views', $this->workDir.'/'.$cacheDir);
        $site = $this->loadSite(['verse.yaml' => "title: T\nurl: https://example.test/{$base}/\n"]);
        $theme = ThemeRegistry::bundled()->find('default');
        Container::getInstance()->instance(ThemeContext::class, new ThemeContext($site, $theme, ViteManifest::load($theme)));

        return $factory;
    }

    private function render(Factory $factory, string $title): string
    {
        return $factory->make('verse::page', [
            'title' => 'Page '.$title,
            'untrusted' => '<script>alert(1)</script>',
            'trusted' => '<em>trusted</em>',
        ])->render();
    }
}
