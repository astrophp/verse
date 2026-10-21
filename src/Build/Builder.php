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
use Astro\Verse\Theme\ThemeRegistry;
use Astro\Verse\View\RequiredViews;
use Astro\Verse\View\SiteRenderer;
use Astro\Verse\View\ThemeContext;
use Closure;
use Throwable;

/**
 * The production pipeline: lock, recovery, theme and content checks, rendering into a staging
 * directory, validation of the staged output and promotion through OutputTransaction.
 */
final class Builder
{
    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly Filesystem $fs = new LocalFilesystem,
        private readonly SiteLoader $loader = new SiteLoader,
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
     * @return int number of HTML pages written
     */
    private function run(BuildContext $context, OutputTransaction $transaction, Warnings $warnings): int
    {
        $theme = $this->themes->find($context->themeName());
        RequiredViews::assertPresent($theme);
        $manifest = ViteManifest::load($theme);

        $warnings->addAll(SiteWarnings::placeholders($context->config));
        $warnings->addAll(SiteWarnings::themeDirectory($context->root));

        $site = $this->loader->load($context, $warnings);
        foreach ($manifest->files() as $file) {
            $site->registry->claim(OutputRegistry::THEME_PREFIX.$file, 'theme asset '.$file, ClaimSource::Theme);
        }

        $cache = $context->root.'/.verse/cache/'.($context->mode === BuildMode::Preview ? 'views-preview' : 'views');
        $this->fs->removeTree($cache);

        $id = OutputTransaction::newId($context->now);
        $staging = $transaction->stagingPath($id);

        try {
            $renderer = new SiteRenderer($site, $theme, new ThemeContext($site, $theme, $manifest), $cache);
            $pages = $this->stage($site, $renderer, $theme->distPath, $manifest, $staging, $transaction->markerContents($id));
            OutputValidator::validate($staging, $site->registry, $manifest);
            $transaction->promote($id);
        } catch (Throwable $exception) {
            $this->fs->removeTree($staging);

            throw $exception;
        }

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
