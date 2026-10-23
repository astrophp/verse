<?php

declare(strict_types=1);

namespace Astro\Verse\Build;

use Astro\Verse\Assets\ViteManifest;
use Astro\Verse\Config\SiteWarnings;
use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Routing\ClaimSource;
use Astro\Verse\Routing\OutputRegistry;
use Astro\Verse\Routing\RouteResolver;
use Astro\Verse\Site\SiteLoader;
use Astro\Verse\Site\SiteModel;
use Astro\Verse\Support\Unicode;
use Astro\Verse\Support\Warnings;
use Astro\Verse\Theme\Theme;
use Astro\Verse\Theme\ThemeRegistry;
use Astro\Verse\View\RequiredViews;
use Astro\Verse\View\SiteHooks;
use Astro\Verse\View\SiteRenderer;
use Astro\Verse\View\ThemeContext;
use Closure;
use Throwable;

/**
 * The production pipeline: lock, recovery, theme and content checks, rendering into a staging
 * directory, validation of the staged output and promotion through OutputTransaction. `checkSite()` runs
 * the same pipeline in private temporary storage and never promotes.
 */
final class Builder
{
    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly Filesystem $fs = new LocalFilesystem,
        private readonly SiteLoader $loader = new SiteLoader,
        private readonly ?string $tempRoot = null,
    ) {}

    public static function bundled(?Filesystem $fs = null): self
    {
        return new self(ThemeRegistry::bundled(), $fs ?? new LocalFilesystem);
    }

    public function build(BuildContext $context): BuildResult
    {
        return $this->buildSite($context->root, static fn (): BuildContext => $context, recoverOutput: $context->outputDir);
    }

    /**
     * Builds the site at `$root`. The build lock is taken and interrupted promotions are recovered before
     * `$loadContext` reads the configuration, so a broken `verse.yaml` cannot strand a half-published output.
     *
     * @param  Closure(): BuildContext  $loadContext  loads and validates the configuration (under the lock)
     * @param  ?Warnings  $warnings  receives every warning, also when the build fails
     * @param  ?string  $recoverOutput  the output whose journal may be recovered before the context exists; `<root>/dist` by default
     */
    public function buildSite(string $root, Closure $loadContext, ?Warnings $warnings = null, ?string $recoverOutput = null): BuildResult
    {
        $started = hrtime(true);
        $warnings ??= new Warnings;
        $root = rtrim($root, '/');

        PathGuard::assertState($root);
        $state = $root.'/.verse';
        $this->fs->mkdir($state);

        $lock = BuildLock::acquire($state.'/lock');
        try {
            (new OutputTransaction($root, PathGuard::normalize($recoverOutput ?? $root.'/dist'), $this->fs))->recover($warnings);

            $context = $loadContext();
            PathGuard::assertSafe($context);
            $context = $context->withOutputDir(PathGuard::normalize($context->outputDir));

            $transaction = new OutputTransaction($context->root, $context->outputDir, $this->fs);
            $transaction->clearStaging();
            $transaction->assertManaged();

            $pages = $this->run($context, $transaction, $warnings);
        } finally {
            $lock->release();
        }

        return new BuildResult($warnings->all(), $context->outputDir, $pages, (hrtime(true) - $started) / 1e9);
    }

    /**
     * Runs the production pipeline in a private temporary directory (`output/` and its own Blade cache) and
     * removes it afterwards. It takes no lock, creates nothing in the site, never writes `dist/` and never
     * recovers an interrupted promotion; it still refuses an output that Verse does not manage, as `build` would.
     *
     * @param  Closure(): BuildContext  $loadContext  loads and validates the configuration
     * @param  ?Warnings  $warnings  receives every warning, also when the check fails
     */
    public function checkSite(Closure $loadContext, ?Warnings $warnings = null): BuildResult
    {
        $started = hrtime(true);
        $warnings ??= new Warnings;

        $context = $loadContext();
        PathGuard::assertSources($context);
        $context = $context->withOutputDir(PathGuard::normalize($context->outputDir));

        if ((glob($context->root.'/.verse/transactions/*.json') ?: []) !== []) {
            $warnings->add('.verse/transactions/ contains a build journal; a build may be running or was interrupted. Check left it alone.');
        }

        $transaction = new OutputTransaction($context->root, $context->outputDir, $this->fs);
        try {
            $transaction->assertManaged();
        } catch (FilesystemException) {
            // A promotion renames the output away and back in two steps, so one probe can fall into the gap between them.
            $transaction->assertManaged();
        }

        $directory = $this->createCheckDirectory($context->root);
        try {
            $prepared = $this->prepare($context, $warnings);
            $pages = $this->stageAndValidate(
                $prepared,
                $directory.'/views',
                $directory.'/output',
                $transaction->markerContents(OutputTransaction::newId($context->now)),
            );
        } catch (Throwable $exception) {
            try {
                $this->fs->removeTree($directory);
            } catch (FilesystemException $failure) {
                $warnings->add('could not remove the temporary directory '.$directory.': '.$failure->detail);
            }

            throw $exception;
        }
        $this->fs->removeTree($directory);

        return new BuildResult($warnings->all(), $context->outputDir, $pages, (hrtime(true) - $started) / 1e9);
    }

    /**
     * Creates a fresh directory of its own in the temporary root. A name that is already taken is never touched:
     * another name is tried (three attempts), so only a directory this check created is ever written to or removed.
     *
     * @throws FilesystemException
     */
    private function createCheckDirectory(string $root): string
    {
        $configured = $this->tempRoot ?? sys_get_temp_dir();
        // validate and create under the same canonical path: `..` after a symbolic link must not slip past the check
        $tempRoot = realpath($configured);
        if (PathGuard::inside($tempRoot === false ? PathGuard::resolve($configured) : $tempRoot, PathGuard::resolve($root))) {
            throw new FilesystemException('the temporary directory '.$configured.' is inside the site. Set TMPDIR to a folder outside it.');
        }
        if ($tempRoot === false || ! is_dir($tempRoot)) {
            throw new FilesystemException('the temporary directory '.$configured.' does not exist. Set TMPDIR to an existing folder outside the site.');
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $directory = $tempRoot.'/verse-check-'.bin2hex(random_bytes(8));

            if (@mkdir($directory, 0700)) {
                return $directory;
            }
            if (! file_exists($directory) && ! is_link($directory)) {
                throw new FilesystemException('cannot create directory '.$directory.': '.(error_get_last()['message'] ?? 'unknown error').'.');
            }
        }

        throw new FilesystemException('cannot check the site: no free temporary directory name was found in '.$tempRoot.'.');
    }

    /**
     * @return int number of HTML pages written
     */
    private function run(BuildContext $context, OutputTransaction $transaction, Warnings $warnings): int
    {
        $prepared = $this->prepare($context, $warnings);

        $cache = $context->root.'/.verse/cache/'.($context->mode === BuildMode::Preview ? 'views-preview' : 'views');
        $this->fs->removeTree($cache);

        $id = OutputTransaction::newId($context->now);
        $staging = $transaction->stagingPath($id);

        try {
            $pages = $this->stageAndValidate($prepared, $cache, $staging, $transaction->markerContents($id));
            $transaction->promote($id);
        } catch (Throwable $exception) {
            $this->fs->removeTree($staging);

            throw $exception;
        }

        return $pages;
    }

    /**
     * Everything that reads the theme and the content, before any cache or staging directory is touched.
     *
     * @return array{site: SiteModel, theme: Theme, manifest: ViteManifest, hooks: ?string}
     */
    private function prepare(BuildContext $context, Warnings $warnings): array
    {
        $theme = $this->themes->find($context->themeName());
        RequiredViews::assertPresent($theme);
        $hooks = SiteHooks::load($context->root);
        $manifest = ViteManifest::load($theme);

        $warnings->addAll(SiteWarnings::placeholders($context->config));
        $warnings->addAll(SiteWarnings::themeDirectory($context->root));

        $site = $this->loader->load($context, $warnings);
        foreach ($manifest->files() as $file) {
            $site->registry->claim(OutputRegistry::THEME_PREFIX.$file, 'theme asset '.$file, ClaimSource::Theme);
        }

        return ['site' => $site, 'theme' => $theme, 'manifest' => $manifest, 'hooks' => $hooks];
    }

    /**
     * Renders the prepared site into `$staging` and validates it.
     *
     * @param  array{site: SiteModel, theme: Theme, manifest: ViteManifest, hooks: ?string}  $prepared
     * @param  string  $cache  the compiled Blade views directory
     * @return int number of HTML pages written
     */
    private function stageAndValidate(array $prepared, string $cache, string $staging, string $marker): int
    {
        ['site' => $site, 'theme' => $theme, 'manifest' => $manifest, 'hooks' => $hooks] = $prepared;

        $renderer = new SiteRenderer($site, $theme, new ThemeContext($site, $theme, $manifest), $cache, $hooks);
        $pages = $this->stage($site, $renderer, $theme->distPath, $manifest, $staging, $marker);
        OutputValidator::validate($staging, $site->registry, $manifest);

        return $pages;
    }

    private function stage(SiteModel $site, SiteRenderer $renderer, string $distPath, ViteManifest $manifest, string $staging, string $marker): int
    {
        $this->fs->mkdir($staging);
        $this->put($staging, OutputTransaction::MARKER, $marker);

        $pages = 0;
        foreach ($renderer->render() as $path => $html) {
            $this->put($staging, $path, $html);
            $pages++;
        }

        $this->put($staging, SearchIndex::PATH, (new SearchIndex($site))->encode());
        if ($site->context->publishesSitemap()) {
            $this->put($staging, Sitemap::PATH, (new Sitemap($site))->xml());
        }

        foreach ($manifest->files() as $file) {
            $this->import($staging, OutputRegistry::THEME_PREFIX.$file, $distPath.'/'.$file);
        }
        foreach ($site->pages as $page) {
            if ($page->route === null) {
                continue;
            }
            foreach ($page->attachments as $attachment) {
                $this->import(
                    $staging,
                    RouteResolver::relativeOutputPath($page->route, Unicode::nfc($attachment->relativePath)),
                    $site->context->root.'/'.$attachment->path,
                );
            }
        }
        foreach ($site->publicFiles as $file) {
            $this->import($staging, $file->outputPath, $site->context->root.'/'.$file->path);
        }

        return $pages;
    }

    private function put(string $staging, string $path, string $contents): void
    {
        $this->fs->mkdir(dirname($staging.'/'.$path));
        $this->fs->writeFile($staging.'/'.$path, $contents);
    }

    private function import(string $staging, string $path, string $source): void
    {
        if (! is_file($source)) {
            throw new FilesystemException('the source file '.$source.' disappeared during the build.');
        }
        $this->fs->mkdir(dirname($staging.'/'.$path));
        $this->fs->copy($source, $staging.'/'.$path);
    }
}
