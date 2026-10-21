<?php

declare(strict_types=1);

namespace Astro\Verse\Theme;

/**
 * A bundled theme: where its files are, which Vite entries it loads and which settings it declares.
 */
final readonly class Theme
{
    /**
     * @param  string  $root  absolute theme directory
     * @param  list<string>  $entries  Vite manifest keys loaded on every page, in order
     * @param  list<string>  $declaredSettings  setting names `verse.yaml` may set (`logo`, `accent`, `toc`)
     * @param  ThemeSettings  $defaults  default value of every setting (built-in defaults for undeclared ones)
     */
    public function __construct(
        public string $name,
        public string $root,
        public string $viewsPath,
        public string $distPath,
        public string $manifestPath,
        public array $entries,
        public array $declaredSettings,
        public ThemeSettings $defaults,
    ) {}
}
