<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Config;

use Astro\Verse\Config\ConfigLoader;
use Astro\Verse\Config\SiteWarnings;
use Astro\Verse\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SiteWarningsTest extends TestCase
{
    #[Test]
    public function it_flags_scaffold_placeholders(): void
    {
        $config = (new ConfigLoader)->parseSite(<<<'YAML'
            title: My Site
            url: https://example.com/
            author:
              name: Your Name
            social_links:
              github: https://github.com/your-username
              medium: https://medium.com/@someone
            YAML, '/none', true);

        $warnings = SiteWarnings::placeholders($config);

        $this->assertCount(3, $warnings);
        $this->assertStringContainsString('example.com', $warnings[0]);
        $this->assertStringContainsString('Your Name', $warnings[1]);
        $this->assertStringContainsString('social_links.github', $warnings[2]);
    }

    #[Test]
    public function it_flags_example_org_and_subdomains(): void
    {
        $org = (new ConfigLoader)->parseSite("title: x\nurl: https://example.org/\n", '/none', true);
        $sub = (new ConfigLoader)->parseSite("title: x\nurl: https://www.example.com/\n", '/none', true);

        $this->assertCount(1, SiteWarnings::placeholders($org));
        $this->assertCount(1, SiteWarnings::placeholders($sub));
    }

    #[Test]
    public function it_stays_quiet_for_real_values(): void
    {
        $config = (new ConfigLoader)->parseSite(<<<'YAML'
            title: Jane Doe
            url: https://jane.example.net/
            author: Zoë Doe
            social_links:
              github: https://github.com/janedoe
            YAML, '/none', true);

        $this->assertSame([], SiteWarnings::placeholders($config));
    }

    #[Test]
    public function it_warns_about_a_themes_directory(): void
    {
        $with = $this->makeSite(['themes/paper/views/x.blade.php' => '']);
        $without = $this->makeSite(['verse.yaml' => 'title: x']);

        $this->assertSame(
            ['site themes are not supported in this version; the themes/ directory is ignored'],
            SiteWarnings::themeDirectory($with),
        );
        $this->assertSame([], SiteWarnings::themeDirectory($without));
    }

    #[Test]
    public function it_no_longer_warns_about_a_theme_directory(): void
    {
        $root = $this->makeSite(['theme/hooks/head.blade.php' => '']);

        $this->assertSame([], SiteWarnings::themeDirectory($root));
    }
}
