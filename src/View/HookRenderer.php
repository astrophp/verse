<?php

declare(strict_types=1);

namespace Astro\Verse\View;

use Astro\Verse\Error\ThemeException;
use Astro\Verse\Error\VerseException;
use ErrorException;
use Illuminate\Support\Arr;
use Illuminate\View\Factory;
use Throwable;

/**
 * Renders a website's hook view at a `@verseHook` placement of a theme view.
 *
 * Unlike theme views, a hook runs with warnings turned into errors: Blade would otherwise print an
 * undefined variable as nothing, and a typo would silently drop an analytics tag from every page.
 */
final class HookRenderer
{
    /**
     * @param  array<string, mixed>  $data  the variables of the including view
     */
    public static function render(Factory $env, Hook $hook, array $data): string
    {
        $view = 'hooks::'.$hook->value;
        if (! $env->exists($view)) {
            return '';
        }

        // an `@` inside the hook lowers error_reporting() below what it was when the hook started
        $reporting = error_reporting();
        set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($reporting): bool {
            if (($severity & (E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE)) === 0) {
                return false;
            }
            if (($reporting & $severity) !== 0 && (error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $env->make($view, Arr::except($data, ['__data', '__path']))->render();
        } catch (Throwable $exception) {
            $innermost = $exception;
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof VerseException) {
                    throw $cause;
                }
                $innermost = $cause;
            }

            throw new ThemeException('the hook failed to render: '.$innermost->getMessage(), $hook->path(), previous: $exception);
        } finally {
            restore_error_handler();
        }
    }
}
