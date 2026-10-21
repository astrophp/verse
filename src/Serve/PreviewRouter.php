<?php

declare(strict_types=1);

namespace Astro\Verse\Serve;

use Astro\Verse\Build\OutputTransaction;

/**
 * Maps a request of `verse serve` to a response. It is pure apart from reading the preview artifact and the
 * status file: the `php -S` shim (`resources/router.php`) only emits what `handle()` returns.
 *
 * Safety: only GET and HEAD; the path is decoded once and every dot segment (except `.well-known`),
 * backslash, control character and encoded slash is refused; the resolved file must be inside the preview
 * directory. Every response is `Cache-Control: no-store`.
 */
final class PreviewRouter
{
    private const array MIME = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'txt' => 'text/plain; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'pdf' => 'application/pdf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
    ];

    private const string HTML = 'text/html; charset=utf-8';

    private const string PENDING_STATUS = '{"generation":null,"result":"pending"}';

    /**
     * @param  string  $previewDir  the artifact directory (`.verse/preview/public` or `.../drafts`)
     * @param  string  $statusFile  `.verse/preview/status.json`
     * @param  int  $markerWaitMicros  how long to wait for the artifact while a rebuild swaps it in place
     */
    public function __construct(
        private readonly string $previewDir,
        private readonly string $statusFile,
        private readonly int $markerWaitMicros = 200_000,
    ) {}

    public function handle(string $method, string $uri): PreviewResponse
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $this->text(405, 'Method Not Allowed', ['Allow' => 'GET, HEAD']);
        }

        $response = $this->route($uri);

        return $method === 'HEAD' ? $response->withoutBody() : $response;
    }

    private function route(string $uri): PreviewResponse
    {
        $path = $this->decode($uri);
        if ($path === null) {
            return $this->notFound(null);
        }

        $status = BuildStatus::read($this->statusFile);
        if ($path === LiveReload::STATUS_PATH) {
            return $this->statusResponse();
        }
        if ($status === null || $status->generation === null) {
            return $this->diagnostic($status);
        }

        $base = $status->basePath;
        if ($path === '/' && $base !== '/') {
            return $this->redirect(302, $base);
        }
        if ($path.'/' === $base) {
            return $this->redirect(301, $base);
        }
        if (! str_starts_with($path, $base)) {
            return $this->notFound($this->artifactGeneration());
        }
        $relative = substr($path, strlen($base));

        // The artifact is swapped in place by a rename; make sure the HTML and the generation come from the same one.
        $attempt = 0;
        do {
            $generation = $this->artifactGeneration(wait: true);
            if ($generation === null) {
                return $this->diagnostic($status);
            }

            $response = $this->serve($path, $relative, $generation);
        } while ($this->artifactGeneration() !== $generation && ++$attempt < 3);

        return $response;
    }

    /**
     * The decoded request path, or null when it must be refused.
     */
    private function decode(string $uri): ?string
    {
        $end = strcspn($uri, '?#');
        $raw = substr($uri, 0, $end);
        if ($raw === '' || $raw[0] !== '/') {
            return null;
        }
        if (preg_match('/%(?:2f|5c)/i', $raw) === 1) {
            return null;
        }

        $path = rawurldecode($raw);
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path) === 1) {
            return null;
        }

        $segments = explode('/', substr($path, 1));
        $last = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            if ($segment === '' && $index !== $last) {
                return null;
            }
            if (str_starts_with($segment, '.') && $segment !== '.well-known') {
                return null;
            }
        }

        return $path;
    }

    private function serve(string $path, string $relative, string $generation): PreviewResponse
    {
        $root = realpath($this->previewDir);
        if ($root === false) {
            return $this->notFound($generation);
        }

        $directory = $relative === '' || str_ends_with($relative, '/');
        $real = $this->resolve($root, $root.'/'.$relative.($directory ? 'index.html' : ''));
        if ($real === null && ! $directory) {
            $candidate = realpath($root.'/'.$relative);
            if ($candidate !== false && is_dir($candidate) && $this->inside($root, $candidate)) {
                return $this->redirect(301, $path.'/');
            }
        }
        if ($real === null) {
            return $this->notFound($generation);
        }

        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $type = self::MIME[$extension] ?? 'application/octet-stream';
        if ($type === self::HTML) {
            $html = @file_get_contents($real);
            if ($html === false) {
                return $this->notFound($generation);
            }

            return $this->html(200, LiveReload::inject($html, $generation));
        }

        // Open the file now: the headers then describe exactly the bytes streamed, even if a rebuild replaces it.
        $stream = @fopen($real, 'rb');
        $stat = $stream === false ? false : fstat($stream);
        if ($stream === false || $stat === false || ($stat['mode'] & 0170000) !== 0100000) {
            if ($stream !== false) {
                fclose($stream);
            }

            return $this->notFound($generation);
        }

        return new PreviewResponse(200, $this->headers($type, $stat['size']), '', $stream);
    }

    /**
     * The real path of a regular file inside the artifact (symbolic links may not leave it), else null.
     */
    private function resolve(string $root, string $candidate): ?string
    {
        $real = realpath($candidate);
        if ($real === false || ! is_file($real) || ! $this->inside($root, $real)) {
            return null;
        }
        foreach (explode('/', substr($real, strlen($root) + 1)) as $segment) {
            if (str_starts_with($segment, '.') && $segment !== '.well-known') {
                return null;
            }
        }

        return $real;
    }

    private function inside(string $root, string $real): bool
    {
        return str_starts_with($real, $root.'/');
    }

    /**
     * The `id` in the artifact's `.verse-output` (the generation it belongs to), null when there is no artifact.
     */
    private function artifactGeneration(bool $wait = false): ?string
    {
        $deadline = hrtime(true) + $this->markerWaitMicros * 1000;
        while (true) {
            $id = OutputTransaction::markerId($this->previewDir);
            if ($id !== null || ! $wait || hrtime(true) >= $deadline) {
                return $id;
            }
            usleep(10_000);
        }
    }

    private function statusResponse(): PreviewResponse
    {
        $text = @file_get_contents($this->statusFile);
        $body = $text !== false && BuildStatus::parse($text) !== null ? $text : self::PENDING_STATUS;

        return new PreviewResponse(200, $this->headers('application/json', strlen($body)), $body);
    }

    private function diagnostic(?BuildStatus $status): PreviewResponse
    {
        if ($status?->result === 'error' && $status->error !== null) {
            $e = $status->error;
            $heading = 'The build failed';
            $detail = '<p>'.self::escape($e['message']).'</p>'
                .($e['file'] !== null ? '<p class="file">'.self::escape($e['file']).'</p>' : '')
                .'<p class="hint">Fix the problem and save; this page reloads when a build succeeds.</p>';
        } else {
            $heading = 'Waiting for the first successful build';
            $detail = '<p class="hint">This page reloads as soon as the build is done.</p>';
        }

        $page = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Verse preview</title><style>'
            .':root{color-scheme:light dark}body{margin:0;padding:32px 16px;font:16px/1.6 system-ui,sans-serif}'
            .'main{max-width:48rem;margin:0 auto}h1{font-size:1.4rem}p{white-space:pre-wrap;word-break:break-word}'
            .'.file,.hint{opacity:.7;font-size:.9rem}'
            .'</style></head><body><main><h1>'.$heading.'</h1>'.$detail.'</main>'
            .LiveReload::script(null).'</body></html>';

        return new PreviewResponse(503, $this->headers(self::HTML, strlen($page)) + ['Retry-After' => '1'], $page);
    }

    private function notFound(?string $generation): PreviewResponse
    {
        $root = $generation === null ? false : realpath($this->previewDir);
        $file = $root === false ? null : $this->resolve($root, $root.'/404.html');
        $page = $file === null ? false : @file_get_contents($file);
        if ($page === false) {
            return $this->text(404, 'Not Found');
        }

        return $this->html(404, LiveReload::inject($page, $generation));
    }

    private function html(int $status, string $html): PreviewResponse
    {
        return new PreviewResponse($status, $this->headers(self::HTML, strlen($html)), $html);
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function text(int $status, string $body, array $extra = []): PreviewResponse
    {
        return new PreviewResponse($status, $this->headers('text/plain; charset=utf-8', strlen($body)) + $extra, $body);
    }

    private function redirect(int $status, string $path): PreviewResponse
    {
        $location = implode('/', array_map(rawurlencode(...), explode('/', $path)));

        return new PreviewResponse($status, $this->headers('text/plain; charset=utf-8', 0) + ['Location' => $location]);
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $type, int $length): array
    {
        return [
            'Content-Type' => $type,
            'Content-Length' => (string) $length,
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
