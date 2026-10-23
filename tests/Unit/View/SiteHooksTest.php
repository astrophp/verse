<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\View;

use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Error\ThemeException;
use Astro\Verse\Error\VerseException;
use Astro\Verse\Tests\Support\TestCase;
use Astro\Verse\View\SiteHooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class SiteHooksTest extends TestCase
{
    /**
     * @param  class-string<VerseException>  $class
     */
    private function assertRejected(string $root, string $class, string $file, string $message): void
    {
        try {
            SiteHooks::load($root);
            $this->fail('Expected '.$class);
        } catch (VerseException $exception) {
            $this->assertInstanceOf($class, $exception);
            $this->assertSame($file, $exception->sourceFile);
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    #[Test]
    public function a_site_without_theme_has_no_hooks(): void
    {
        $this->assertNull(SiteHooks::load($this->makeSite(['verse.yaml' => 'title: x'])));
    }

    #[Test]
    public function an_empty_theme_folder_has_no_hooks(): void
    {
        $root = $this->makeSite();
        mkdir($root.'/theme');

        $this->assertNull(SiteHooks::load($root));
    }

    #[Test]
    public function it_returns_the_hooks_directory(): void
    {
        $root = $this->makeSite(['theme/hooks/head.blade.php' => '', 'theme/hooks/page-after.blade.php' => '']);

        $this->assertSame($root.'/theme/hooks', SiteHooks::load($root));
    }

    #[Test]
    public function an_empty_hooks_folder_is_valid(): void
    {
        $root = $this->makeSite();
        mkdir($root.'/theme/hooks', 0775, true);

        $this->assertSame($root.'/theme/hooks', SiteHooks::load($root));
    }

    #[Test]
    public function hidden_entries_are_ignored(): void
    {
        $root = $this->makeSite(['theme/.DS_Store' => '', 'theme/hooks/.DS_Store' => '', 'theme/hooks/.head.swp' => '', 'theme/hooks/head.blade.php' => '']);

        $this->assertSame($root.'/theme/hooks', SiteHooks::load($root));
    }

    #[Test]
    public function theme_must_be_a_folder(): void
    {
        $this->assertRejected($this->makeSite(['theme' => 'x']), ThemeException::class, 'theme', 'must be a folder');
    }

    #[DataProvider('foreignEntries')]
    #[Test]
    public function only_hooks_is_allowed_in_theme(string $path): void
    {
        $this->assertRejected(
            $this->makeSite([$path => 'x']),
            ThemeException::class,
            'theme/'.explode('/', substr($path, 6))[0],
            'only theme/hooks/ is supported in this version',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignEntries(): array
    {
        return [
            'views folder' => ['theme/views/x.blade.php'],
            'a stray file' => ['theme/theme.yaml'],
        ];
    }

    #[Test]
    public function an_empty_foreign_folder_is_rejected(): void
    {
        $root = $this->makeSite();
        mkdir($root.'/theme/views', 0775, true);

        $this->assertRejected($root, ThemeException::class, 'theme/views', 'only theme/hooks/ is supported');
    }

    #[Test]
    public function hooks_must_be_a_folder(): void
    {
        $this->assertRejected($this->makeSite(['theme/hooks' => 'x']), ThemeException::class, 'theme/hooks', 'must be a folder');
    }

    #[DataProvider('badHookFiles')]
    #[Test]
    public function an_unknown_hook_file_is_rejected_with_the_known_names(string $path, string $reported): void
    {
        $this->assertRejected(
            $this->makeSite([$path => 'x']),
            ThemeException::class,
            $reported,
            'unknown hook file. Name it <hook>.blade.php; known hooks: head, body-start,',
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badHookFiles(): array
    {
        return [
            'unknown name' => ['theme/hooks/footer.blade.php', 'theme/hooks/footer.blade.php'],
            'wrong extension' => ['theme/hooks/head.html', 'theme/hooks/head.html'],
            'plain php' => ['theme/hooks/head.php', 'theme/hooks/head.php'],
            'a subfolder' => ['theme/hooks/sub/head.blade.php', 'theme/hooks/sub'],
        ];
    }

    #[Test]
    public function a_hook_must_be_a_regular_file(): void
    {
        $root = $this->makeSite();
        mkdir($root.'/theme/hooks/head.blade.php', 0775, true);

        $this->assertRejected($root, ThemeException::class, 'theme/hooks/head.blade.php', 'unknown hook file');
    }

    #[Test]
    public function symbolic_links_are_rejected_everywhere_under_theme(): void
    {
        $target = $this->makeSite(['hooks/head.blade.php' => 'x', 'real.blade.php' => 'x']);

        $theme = $this->makeSite();
        symlink($target, $theme.'/theme');
        $this->assertRejected($theme, FilesystemException::class, 'theme', 'symbolic links are not supported');

        $dangling = $this->makeSite();
        symlink($dangling.'/missing', $dangling.'/theme');
        $this->assertRejected($dangling, FilesystemException::class, 'theme', 'symbolic links are not supported');

        $hooks = $this->makeSite();
        mkdir($hooks.'/theme');
        symlink($target.'/hooks', $hooks.'/theme/hooks');
        $this->assertRejected($hooks, FilesystemException::class, 'theme/hooks', 'symbolic links are not supported');

        $file = $this->makeSite(['theme/hooks/body-end.blade.php' => 'x']);
        symlink($target.'/real.blade.php', $file.'/theme/hooks/head.blade.php');
        $this->assertRejected($file, FilesystemException::class, 'theme/hooks/head.blade.php', 'symbolic links are not supported');
    }
}
