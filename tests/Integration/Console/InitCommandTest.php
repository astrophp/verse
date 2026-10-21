<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Console;

use Astro\Verse\Console\Application;
use Astro\Verse\Console\InitCommand;
use Astro\Verse\Init\ProjectInitializer;
use Astro\Verse\Tests\Support\TestCase;
use Astro\Verse\Verse;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\ApplicationTester;

final class InitCommandTest extends TestCase
{
    private const array SCAFFOLD = [
        '.gitignore',
        'content/about.md',
        'content/articles/hello-verse.md',
        'content/articles/markdown-showcase/diagram.svg',
        'content/articles/markdown-showcase/page.md',
        'verse.yaml',
    ];

    private const string UTC_LINE = 'Dates are interpreted in UTC unless you set `timezone` in verse.yaml.';

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

    /**
     * @param  list<string>  $arguments
     */
    private function runInit(string $cwd, array $arguments, ?ProjectInitializer $initializer = null): ApplicationTester
    {
        chdir($cwd);
        $application = new Application;
        if ($initializer !== null) {
            $application->addCommand(new InitCommand($initializer));
        }
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'init'] + ($arguments === [] ? [] : ['path' => $arguments[0]]), ['capture_stderr_separately' => true]);

        return $tester;
    }

    /**
     * @return list<string> site-relative paths of every file below `$root`, sorted
     */
    private static function files(string $root): array
    {
        return array_keys(self::snapshot($root));
    }

    /**
     * @return list<string>
     */
    private static function entries(string $directory): array
    {
        return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
    }

    private function parent(): string
    {
        return $this->makeSite();
    }

    #[Test]
    public function a_missing_folder_is_created_through_a_temporary_directory(): void
    {
        $parent = $this->parent();
        $tester = $this->runInit($parent, ['demo-site']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertSame(self::SCAFFOLD, self::files($parent.'/demo-site'));
        $this->assertSame(['demo-site'], self::entries($parent));
        $this->assertSame('', $tester->getErrorOutput());
    }

    #[Test]
    public function the_scaffold_is_copied_byte_for_byte(): void
    {
        $parent = $this->parent();
        $this->runInit($parent, ['demo-site']);

        $this->assertSame(
            self::snapshot(Verse::packageRoot().'/resources/scaffold'),
            self::snapshot($parent.'/demo-site'),
        );
    }

    #[Test]
    public function the_scaffold_declares_the_documented_samples(): void
    {
        $scaffold = self::snapshot(Verse::packageRoot().'/resources/scaffold');

        $this->assertSame(self::SCAFFOLD, array_keys($scaffold));
        $this->assertSame("/dist/\n/.verse/\n", $scaffold['.gitignore']);
        $this->assertStringContainsString("\ndate: 2026-10-20\n", $scaffold['content/articles/hello-verse.md']);
        $this->assertStringContainsString('```php', $scaffold['content/articles/hello-verse.md']);
        $this->assertSame(2, preg_match_all('/^## /m', $scaffold['content/articles/hello-verse.md']));
        $this->assertStringNotContainsString('articles', $scaffold['verse.yaml']);
        // the showcase is a draft: shown by `verse serve`, never published by `verse build`
        $this->assertStringContainsString("\ndraft: true\n", $scaffold['content/articles/markdown-showcase/page.md']);
    }

    #[Test]
    public function the_current_directory_is_set_up_with_a_dot_and_without_an_argument(): void
    {
        $empty = $this->parent();
        $this->assertSame(0, $this->runInit($empty, ['.'])->getStatusCode());
        $this->assertSame(self::SCAFFOLD, self::files($empty));

        $other = $this->parent();
        $tester = $this->runInit($other, []);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(self::SCAFFOLD, self::files($other));
    }

    #[Test]
    public function a_directory_with_only_a_git_directory_is_accepted(): void
    {
        $root = $this->makeSite(['.git/HEAD' => "ref: refs/heads/main\n"]);
        $tester = $this->runInit($root, ['.']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertSame(['.git/HEAD', ...self::SCAFFOLD], self::files($root));
        $this->assertSame("ref: refs/heads/main\n", file_get_contents($root.'/.git/HEAD'));
    }

    #[Test]
    public function a_directory_with_only_a_git_file_is_accepted(): void
    {
        $root = $this->makeSite(['.git' => "gitdir: ../elsewhere\n"]);
        $tester = $this->runInit($root, ['.']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertSame(['.git', ...self::SCAFFOLD], self::files($root));
        $this->assertSame("gitdir: ../elsewhere\n", file_get_contents($root.'/.git'));
    }

    #[Test]
    public function a_directory_with_only_a_ds_store_is_accepted(): void
    {
        $root = $this->makeSite(['.DS_Store' => 'junk']);
        $tester = $this->runInit($root, ['.']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertSame(['.DS_Store', ...self::SCAFFOLD], self::files($root));
    }

    #[Test]
    public function another_file_makes_init_refuse_and_leaves_the_directory_unchanged(): void
    {
        $root = $this->makeSite(['.git/HEAD' => 'ref', 'notes.txt' => 'mine', 'src/app.php' => '<?php']);
        $before = self::snapshot($root);
        $tester = $this->runInit($root, ['.']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('error: filesystem error: cannot initialize '.$root, $tester->getErrorOutput());
        $this->assertStringContainsString('found notes.txt, src', $tester->getErrorOutput());
        $this->assertStringNotContainsString('.git', str_replace($root, '', $tester->getErrorOutput()));
        $this->assertSame('', $tester->getDisplay());
        $this->assertSame($before, self::snapshot($root));
        $this->assertSame(['.git', 'notes.txt', 'src'], self::entries($root));
    }

    #[Test]
    public function the_refusal_lists_at_most_ten_entries_sorted(): void
    {
        $files = [];
        foreach (range(1, 13) as $number) {
            $files[sprintf('file-%02d.txt', 14 - $number)] = 'x';
        }
        $root = $this->makeSite($files);
        $tester = $this->runInit($root, ['.']);

        $this->assertSame(1, $tester->getStatusCode());
        $error = $tester->getErrorOutput();
        $this->assertStringContainsString('found file-01.txt, file-02.txt, file-03.txt, file-04.txt, file-05.txt, file-06.txt, file-07.txt, file-08.txt, file-09.txt, file-10.txt, and 3 more', $error);
        $this->assertStringNotContainsString('file-11.txt', $error);
    }

    #[Test]
    public function a_file_as_the_target_is_an_error(): void
    {
        $root = $this->makeSite(['site' => 'a file']);
        $tester = $this->runInit($root, ['site']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('it exists and is not a directory', $tester->getErrorOutput());
        $this->assertSame('a file', file_get_contents($root.'/site'));
    }

    #[Test]
    public function a_missing_parent_directory_is_an_error(): void
    {
        $root = $this->parent();
        $tester = $this->runInit($root, ['missing/site']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('error: filesystem error: cannot initialize missing/site', $tester->getErrorOutput());
        $this->assertSame([], self::entries($root));
    }

    #[Test]
    public function an_unwritable_parent_is_an_error(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('permissions do not apply to root');
        }
        $root = $this->parent();
        chmod($root, 0555);

        try {
            $tester = $this->runInit($root, ['site']);
        } finally {
            chmod($root, 0775);
        }

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('is not writable', $tester->getErrorOutput());
        $this->assertSame([], self::entries($root));
    }

    #[Test]
    public function a_failure_in_an_existing_directory_removes_only_what_was_created(): void
    {
        $root = $this->makeSite(['.git/HEAD' => 'ref', '.DS_Store' => 'junk']);
        // another process takes verse.yaml's place just before it is written
        $initializer = new ProjectInitializer(beforeCreate: self::blockPath('verse.yaml'));
        $tester = $this->runInit($root, ['.'], $initializer);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('error: filesystem error: cannot create '.$root.'/verse.yaml', $tester->getErrorOutput());
        $this->assertStringContainsString('Removed the files created so far.', $tester->getErrorOutput());
        $this->assertStringNotContainsString('Could not remove', $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
        // the injected blocker is not ours; everything else is back to the starting state
        $this->assertSame(['.DS_Store', '.git', 'verse.yaml'], self::entries($root));
        $this->assertSame('ref', file_get_contents($root.'/.git/HEAD'));
        $this->assertSame('junk', file_get_contents($root.'/.DS_Store'));
        $this->assertSame([], self::entries($root.'/verse.yaml'));
    }

    #[Test]
    public function entries_that_cannot_be_removed_are_reported_and_left_alone(): void
    {
        $root = $this->makeSite(['.git' => 'gitdir: x']);
        $initializer = new ProjectInitializer(beforeCreate: self::blockPath('content/articles/hello-verse.md'));
        $tester = $this->runInit($root, ['.'], $initializer);

        $this->assertSame(1, $tester->getStatusCode());
        $error = $tester->getErrorOutput();
        $this->assertStringContainsString('cannot create '.$root.'/content/articles/hello-verse.md', $error);
        $this->assertStringContainsString('Could not remove: '.$root.'/content/articles, '.$root.'/content.', $error);
        // files created by this run are gone, the foreign directory and its parents stay
        $this->assertSame(['.git', 'content'], self::entries($root));
        $this->assertSame(['articles'], self::entries($root.'/content'));
        $this->assertSame(['hello-verse.md'], self::entries($root.'/content/articles'));
    }

    #[Test]
    public function a_failure_for_a_new_folder_leaves_no_temporary_directory_behind(): void
    {
        $parent = $this->parent();
        $initializer = new ProjectInitializer(beforeCreate: self::blockPath('content/articles/hello-verse.md'));
        $tester = $this->runInit($parent, ['demo-site'], $initializer);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('error: filesystem error: cannot create', $tester->getErrorOutput());
        $this->assertSame([], self::entries($parent));
    }

    #[Test]
    public function the_temporary_directory_sits_beside_the_target_while_it_is_written(): void
    {
        $parent = $this->parent();
        $seen = [];
        $initializer = new ProjectInitializer(beforeCreate: function (string $relative, string $absolute) use (&$seen): void {
            $seen[] = $absolute;
        });
        $this->runInit($parent, ['demo-site'], $initializer);

        $this->assertNotSame([], $seen);
        foreach ($seen as $path) {
            $this->assertMatchesRegularExpression('~^'.preg_quote($parent, '~').'/\.demo-site\.verse-init-[0-9a-f]{8}/~', $path);
        }
        $this->assertSame(['demo-site'], self::entries($parent));
    }

    #[Test]
    public function the_output_lists_the_files_the_utc_note_and_next_steps(): void
    {
        $parent = $this->parent();
        $tester = $this->runInit($parent, ['demo-site']);

        $expected = "Created 6 files in {$parent}/demo-site:\n"
            ."  .gitignore\n  content/about.md\n  content/articles/hello-verse.md\n"
            ."  content/articles/markdown-showcase/diagram.svg\n  content/articles/markdown-showcase/page.md\n  verse.yaml\n"
            ."\n".self::UTC_LINE."\n\nNext steps:\n  cd demo-site\n  verse build\n";
        $this->assertSame($expected, $tester->getDisplay());
        $this->assertStringNotContainsString('serve', $tester->getDisplay());
    }

    #[Test]
    public function setting_up_the_current_directory_does_not_suggest_cd(): void
    {
        $root = $this->parent();
        $display = $this->runInit($root, ['.'])->getDisplay();

        $this->assertStringContainsString(self::UTC_LINE, $display);
        $this->assertStringNotContainsString('cd ', $display);
        $this->assertStringContainsString("Next steps:\n  verse build\n", $display);
    }

    #[Test]
    public function an_existing_empty_folder_given_by_name_is_filled_and_cd_is_suggested(): void
    {
        $parent = $this->parent();
        mkdir($parent.'/site');
        $display = $this->runInit($parent, ['site'])->getDisplay();

        $this->assertSame(self::SCAFFOLD, self::files($parent.'/site'));
        $this->assertStringContainsString("Next steps:\n  cd site\n  verse build\n", $display);
    }

    #[Test]
    public function a_colliding_temporary_directory_is_left_untouched_and_another_name_is_used(): void
    {
        $parent = $this->parent();
        mkdir($parent.'/.demo.verse-init-taken');
        file_put_contents($parent.'/.demo.verse-init-taken/precious.txt', 'keep');
        $names = ['taken', 'taken', 'free'];
        $initializer = new ProjectInitializer(suffix: static function () use (&$names): string {
            return array_shift($names) ?? 'unexpected';
        });

        $this->assertSame(0, $this->runInit($parent, ['demo'], $initializer)->getStatusCode());

        $this->assertSame(self::SCAFFOLD, self::files($parent.'/demo'));
        $this->assertSame('keep', file_get_contents($parent.'/.demo.verse-init-taken/precious.txt'));
        $this->assertSame(['.demo.verse-init-taken', 'demo'], self::entries($parent));
    }

    #[Test]
    public function when_every_attempt_collides_nothing_is_changed_or_removed(): void
    {
        $parent = $this->parent();
        mkdir($parent.'/.demo.verse-init-taken');
        file_put_contents($parent.'/.demo.verse-init-taken/precious.txt', 'keep');
        $initializer = new ProjectInitializer(suffix: static fn (): string => 'taken');

        $tester = $this->runInit($parent, ['demo'], $initializer);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('no free temporary directory name', $tester->getDisplay().$tester->getErrorOutput());
        $this->assertSame('keep', file_get_contents($parent.'/.demo.verse-init-taken/precious.txt'));
        $this->assertSame(['.demo.verse-init-taken'], self::entries($parent));
    }

    #[Test]
    public function relative_and_absolute_paths_resolve_alike(): void
    {
        $parent = $this->parent();
        $elsewhere = $this->parent();

        $this->assertSame(0, $this->runInit($parent, ['nested-site'])->getStatusCode());
        $this->assertSame(0, $this->runInit($elsewhere, [$parent.'/absolute-site'])->getStatusCode());
        $this->assertSame(0, $this->runInit($parent, ['./dotted-site/'])->getStatusCode());
        $this->assertSame(0, $this->runInit($parent, ['nested-site/../other-site'])->getStatusCode());

        $this->assertSame(['absolute-site', 'dotted-site', 'nested-site', 'other-site'], self::entries($parent));
        $this->assertSame(self::SCAFFOLD, self::files($parent.'/nested-site'));
        $this->assertSame(self::SCAFFOLD, self::files($parent.'/absolute-site'));
        $this->assertSame(self::SCAFFOLD, self::files($parent.'/other-site'));
        $this->assertSame([], self::entries($elsewhere));
    }

    #[Test]
    public function the_scaffold_builds_with_placeholder_warnings(): void
    {
        $parent = $this->parent();
        $this->runInit($parent, ['demo-site']);

        $root = realpath($parent.'/demo-site');
        $this->assertIsString($root);
        chdir($root);
        $application = new Application;
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'build'], ['capture_stderr_separately' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $errors = $tester->getErrorOutput();
        $this->assertStringContainsString('warning: verse.yaml: url uses the placeholder host "example.com"', $errors);
        $this->assertStringContainsString('Your Name', $errors);
        $this->assertStringContainsString('your-username', $errors);
        $this->assertFileExists($root.'/dist/index.html');
        $this->assertFileExists($root.'/dist/articles/hello-verse/index.html');
        $this->assertFileExists($root.'/dist/about/index.html');
        $this->assertFileExists($root.'/dist/tags/verse/index.html');
        $this->assertFileExists($root.'/dist/articles/index.html');
        $this->assertDirectoryDoesNotExist($root.'/dist/articles/markdown-showcase');
        $this->assertStringContainsString('Hello, Verse', (string) file_get_contents($root.'/dist/articles/hello-verse/index.html'));
        // the home page lists the one article; the archive counts it
        $this->assertStringContainsString('Hello, Verse', (string) file_get_contents($root.'/dist/index.html'));
        $this->assertStringContainsString('1 article', (string) file_get_contents($root.'/dist/articles/index.html'));
    }

    /**
     * A before-create hook that puts a directory where the given scaffold file is about to be written.
     *
     * @return Closure(string, string): void
     */
    private static function blockPath(string $relativePath): Closure
    {
        return static function (string $relative, string $absolute) use ($relativePath): void {
            if ($relative === $relativePath) {
                mkdir($absolute, 0775);
            }
        };
    }
}
