<?php

declare(strict_types=1);

use Astro\Verse\Error\ThemeException;
use Astro\Verse\View\ThemeContext;
use Illuminate\Container\Container;

if (! function_exists('verse')) {
    /**
     * The theme helper of the page being rendered: `verse()->url('/articles/')` in a view. Global because compiled
     * Blade views have no namespace.
     *
     * @throws ThemeException outside a render
     */
    function verse(): ThemeContext
    {
        $container = Container::getInstance();
        if (! $container->bound(ThemeContext::class)) {
            throw new ThemeException('verse() is only available while Verse renders a theme view.');
        }

        return $container->make(ThemeContext::class);
    }
}
