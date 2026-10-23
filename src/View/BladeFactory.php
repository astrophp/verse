<?php

declare(strict_types=1);

namespace Astro\Verse\View;

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

    public static function create(string $viewsPath, string $cachePath): Factory
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

        $factory = new Factory($resolver, $finder, new Dispatcher($container));
        $factory->setContainer($container);

        $container->instance('view', $factory);
        $container->instance(FactoryContract::class, $factory);
        $container->instance(Factory::class, $factory);
        $container->instance('blade.compiler', $compiler);
        $container->instance(BladeCompiler::class, $compiler);

        $compiler->directive('verseAssets', static fn (): string => '<?php echo verse()->assetTags(); ?>');

        foreach (self::componentNames($viewsPath.'/components') as $name) {
            $compiler->component(self::NAMESPACE.'::components.'.$name, $name);
        }

        return $factory;
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
