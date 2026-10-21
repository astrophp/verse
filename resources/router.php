<?php

declare(strict_types=1);

/*
 * Router script of `verse serve` (php -S ... router.php). It never lets the built-in server serve or execute a
 * file itself: every request is answered by Astro\Verse\Serve\PreviewRouter and the script always returns true.
 */

use Astro\Verse\Serve\PreviewRouter;

(static function (): void {
    $autoloaders = [
        __DIR__.'/../vendor/autoload.php',
        __DIR__.'/../../../autoload.php',
    ];
    foreach ($autoloaders as $autoloader) {
        if (is_file($autoloader)) {
            require_once $autoloader;

            break;
        }
    }

    try {
        if (! class_exists(PreviewRouter::class)) {
            throw new RuntimeException('Verse dependencies are missing.');
        }

        $directory = getenv('VERSE_PREVIEW_DIR');
        $statusFile = getenv('VERSE_STATUS_FILE');
        if (! is_string($directory) || ! is_string($statusFile)) {
            throw new RuntimeException('VERSE_PREVIEW_DIR and VERSE_STATUS_FILE must be set.');
        }

        $method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $response = (new PreviewRouter($directory, $statusFile))->handle($method, $uri);
    } catch (Throwable $exception) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'Verse preview error: '.$exception->getMessage()."\n";

        return;
    }

    http_response_code($response->status);
    foreach ($response->headers as $name => $value) {
        header($name.': '.$value);
    }
    $response->emit();
})();

return true;
