<?php

declare(strict_types=1);

namespace Astro\Verse\View;

use Astro\Verse\Error\ThemeException;
use Illuminate\Container\Container;
use Illuminate\Contracts\View\Factory as FactoryContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Component;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;

/**
 * Builds a standalone Blade view factory (no Laravel application).
 *
 * Components are registered explicitly as aliases to anonymous views:
 * Laravel checks aliases before class-name guessing (which needs an
 * Application) and anonymous component paths only after it.
 */
final class BladeFactory
{
    public const string NAMESPACE = 'verse';

    public static function create(string $viewsPath, string $cachePath, ?string $hooksPath = null): Factory
    {
        // Component keeps static caches and resolves views through the global container.
        Component::flushCache();
        Component::forgetFactory();

        $container = new Container;
        Container::setInstance($container);

        $files = new Filesystem;
        if (! $files->isDirectory($cachePath)) {
            $files->makeDirectory($cachePath, 0775, true);
        }

        $compiler = new BladeCompiler($files, $cachePath);

        $resolver = new EngineResolver;
        $resolver->register('blade', fn (): CompilerEngine => new CompilerEngine($compiler, $files));
        $resolver->register('php', fn (): PhpEngine => new PhpEngine($files));

        $finder = new FileViewFinder($files, [$viewsPath]);
        $finder->addNamespace(self::NAMESPACE, $viewsPath);
        if ($hooksPath !== null) {
            $finder->addNamespace('hooks', $hooksPath);
        }

        $factory = new Factory($resolver, $finder, new Dispatcher($container));
        $factory->setContainer($container);

        $container->instance('view', $factory);
        $container->instance(FactoryContract::class, $factory);
        $container->instance(Factory::class, $factory);
        $container->instance('blade.compiler', $compiler);
        $container->instance(BladeCompiler::class, $compiler);

        $compiler->directive('verseAssets', static fn (): string => '<?php echo verse()->assetTags(); ?>');

        $compiler->directive('verseHook', static fn (string $expression): string => self::hookDirective($expression, $compiler, $viewsPath, $hooksPath));

        foreach (self::componentNames($viewsPath.'/components') as $name) {
            $compiler->component(self::NAMESPACE.'::components.'.$name, $name);
        }

        return $factory;
    }

    /**
     * Compiles `@verseHook('head')`; the argument must be the quoted name of a known hook.
     */
    private static function hookDirective(string $expression, BladeCompiler $compiler, string $viewsPath, ?string $hooksPath): string
    {
        $path = $compiler->getPath();
        if ($hooksPath !== null && str_starts_with($path, rtrim($hooksPath, '/').'/')) {
            // a hook placing hooks could include itself forever
            throw new ThemeException('@verseHook places hooks in theme views; a hook cannot place another hook. Remove it.', Hook::DIRECTORY.'/'.basename($path));
        }
        $file = self::themeFile($path, $viewsPath);

        if (preg_match('/^\s*([\'"])([^\'"\\\\$]*)\1\s*$/', $expression, $matches) !== 1) {
            throw new ThemeException('@verseHook takes a hook name in quotes, e.g. @verseHook(\'head\'). Got @verseHook('.$expression.').', $file);
        }
        $hook = Hook::tryFrom($matches[2]);
        if ($hook === null) {
            throw new ThemeException('unknown hook "'.$matches[2].'" in @verseHook. Known hooks: '.Hook::list().'.', $file);
        }

        return '<?php echo \\'.HookRenderer::class.'::render($__env, \\'.Hook::class.'::'.$hook->name.', get_defined_vars()); ?>';
    }

    /**
     * The path of a view being compiled as `themes/<name>/views/…`, for error messages.
     */
    private static function themeFile(?string $path, string $viewsPath): ?string
    {
        if ($path === null) {
            return null;
        }
        $views = rtrim($viewsPath, '/');
        $relative = str_starts_with($path, $views.'/') ? substr($path, strlen($views) + 1) : basename($path);

        return 'themes/'.basename(dirname($views)).'/views/'.$relative;
    }

    /**
     * @return list<string>
     */
    private static function componentNames(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $names = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if (str_ends_with($entry, '.blade.php')) {
                $names[] = substr($entry, 0, -strlen('.blade.php'));
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }
}
