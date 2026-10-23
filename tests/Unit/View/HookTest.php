<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\View;

use Astro\Verse\Tests\Support\TestCase;
use Astro\Verse\View\Hook;
use PHPUnit\Framework\Attributes\Test;

final class HookTest extends TestCase
{
    #[Test]
    public function it_names_the_site_relative_view_path(): void
    {
        $this->assertSame('theme/hooks/head.blade.php', Hook::Head->path());
        $this->assertSame('theme/hooks/body-start.blade.php', Hook::BodyStart->path());
    }

    #[Test]
    public function it_lists_every_hook_in_order(): void
    {
        $this->assertSame(
            'head, body-start, main-start, main-end, body-end, article-before, article-after, page-before, page-after',
            Hook::list(),
        );
    }
}
