<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Serve;

use Astro\Verse\Serve\BuildStatus;
use Astro\Verse\Serve\PreviewResponse;
use Astro\Verse\Serve\PreviewRouter;
use Astro\Verse\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class PreviewRouterTest extends TestCase
{
    private const string GENERATION = '20261020-120000-aabbccdd';

    private string $site = '';

    private string $preview = '';

    /**
     * @param  array<string, string>  $files  files of the artifact
     * @param  ?array{generation: ?string, basePath: string, result?: 'ok'|'error'}  $status  null writes no status file
     */
    private function router(array $files = [], ?array $status = ['generation' => self::GENERATION, 'basePath' => '/'], bool $marker = true): PreviewRouter
    {
        $files += [
            'index.html' => "<html><body><h1>Home</h1></body></html>\n",
            '404.html' => "<html><body><h1>Missing</h1></body></html>\n",
            'style.css' => 'body{}',
            'dir/index.html' => '<html><body>Dir</body></html>',
            '.well-known/security.txt' => 'Contact: x',
            'secret.txt' => 'public file',
        ];
        if ($marker) {
            $files['.verse-output'] = '{"id":"'.self::GENERATION.'","verse":"0.1.0-dev"}'."\n";
        }
        $this->site = $this->makeSite(['verse.yaml' => 'title: X'.PHP_EOL, 'content/about.md' => '# x']);
        $this->preview = $this->site.'/.verse/preview/public';
        $this->writeFiles($this->preview, $files);

        if ($status !== null) {
            (new BuildStatus($status['generation'], $status['basePath'], $status['result'] ?? 'ok', null, '2026-10-20T12:00:00.000Z'))
                ->write($this->site.'/.verse/preview/status.json');
        }

        return new PreviewRouter($this->preview, $this->site.'/.verse/preview/status.json', markerWaitMicros: 0);
    }

    private function get(PreviewRouter $router, string $uri): PreviewResponse
    {
        return $router->handle('GET', $uri);
    }

    private function contents(PreviewResponse $response): string
    {
        $this->assertIsResource($response->stream);

        return (string) stream_get_contents($response->stream);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedPaths(): iterable
    {
        yield 'parent segment' => ['/../x'];
        yield 'nested parent segments' => ['/a/../../x'];
        yield 'parent after dir' => ['/dir/../index.html'];
        yield 'single dot' => ['/./index.html'];
        yield 'encoded dots' => ['/%2e%2e/x'];
        yield 'encoded dots upper' => ['/%2E%2E/x'];
        yield 'mixed encoded dot' => ['/.%2e/x'];
        yield 'encoded slash' => ['/dir%2findex.html'];
        yield 'encoded slash traversal' => ['/..%2fx'];
        yield 'encoded slash upper' => ['/dir%2Findex.html'];
        yield 'encoded NUL' => ['/index.html%00.png'];
        yield 'encoded backslash' => ['/dir%5cindex.html'];
        yield 'encoded backslash upper' => ['/dir%5Cindex.html'];
        yield 'literal backslash' => ['/dir\\index.html'];
        yield 'control character' => ['/index%0d%0a.html'];
        yield 'dotfile' => ['/.env'];
        yield 'marker' => ['/.verse-output'];
        yield 'dot directory' => ['/.git/config'];
        yield 'dotfile inside dir' => ['/dir/.hidden'];
        yield 'double slash host' => ['//evil.example/x'];
        yield 'empty middle segment' => ['/dir//index.html'];
        yield 'absolute-form uri' => ['http://evil.example/'];
        yield 'verse.yaml' => ['/verse.yaml'];
        yield 'content source' => ['/content/about.md'];
        yield 'state directory' => ['/.verse/preview/status.json'];
    }

    #[Test]
    #[DataProvider('refusedPaths')]
    public function unsafe_or_source_paths_are_not_found(string $uri): void
    {
        $response = $this->get($this->router(), $uri);

        $this->assertSame(404, $response->status, $uri);
        $this->assertStringNotContainsString('public file', $response->body);
        $this->assertNull($response->stream);
        $this->assertSame('no-store', $response->header('Cache-Control'));
    }

    #[Test]
    public function the_well_known_directory_is_served(): void
    {
        $response = $this->get($this->router(), '/.well-known/security.txt');

        $this->assertSame(200, $response->status);
        $this->assertSame('Contact: x', $this->contents($response));
        $this->assertSame('text/plain; charset=utf-8', $response->header('Content-Type'));
    }

    #[Test]
    public function the_root_serves_index_html_with_the_reload_script_for_its_generation(): void
    {
        $response = $this->get($this->router(), '/');

        $this->assertSame(200, $response->status);
        $this->assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        $this->assertSame('no-store', $response->header('Cache-Control'));
        $this->assertStringContainsString('<h1>Home</h1>', $response->body);
        $this->assertStringContainsString('var g="'.self::GENERATION.'"', $response->body);
        $this->assertStringContainsString('fetch("/__verse/status"', $response->body);
        $this->assertSame(1, substr_count($response->body, '<script'));
        $this->assertLessThan(strpos($response->body, '</body>'), strpos($response->body, '<script'));
        $this->assertSame((string) strlen($response->body), $response->header('Content-Length'));
    }

    #[Test]
    public function the_generation_comes_from_the_artifact_marker_not_from_the_status_file(): void
    {
        $router = $this->router(status: ['generation' => '20250101-000000-00000000', 'basePath' => '/']);

        $this->assertStringContainsString('var g="'.self::GENERATION.'"', $this->get($router, '/')->body);
    }

    #[Test]
    public function a_page_without_a_body_tag_gets_the_script_appended(): void
    {
        $response = $this->get($this->router(['index.html' => '<p>fragment</p>']), '/');

        $this->assertStringStartsWith('<p>fragment</p><script', $response->body);
    }

    #[Test]
    public function other_files_are_streamed_with_their_mime_type_and_without_the_script(): void
    {
        $response = $this->get($this->router(), '/style.css');

        $this->assertSame(200, $response->status);
        $this->assertSame('body{}', $this->contents($response));
        $this->assertSame('', $response->body);
        $this->assertSame('text/css; charset=utf-8', $response->header('Content-Type'));
        $this->assertSame('6', $response->header('Content-Length'));
    }

    #[Test]
    public function a_streamed_file_keeps_its_bytes_and_length_when_it_is_replaced_before_emitting(): void
    {
        $router = $this->router(['big.css' => 'original-bytes']);
        $response = $this->get($router, '/big.css');

        // a rebuild promotes a new artifact: the file is replaced on disk before the shim emits
        file_put_contents($this->preview.'/big.css.new', 'a completely different and longer replacement');
        rename($this->preview.'/big.css.new', $this->preview.'/big.css');

        $this->assertSame('14', $response->header('Content-Length'));
        $this->assertSame('original-bytes', $this->contents($response));
        $this->assertSame('a completely different and longer replacement', (string) file_get_contents($this->preview.'/big.css'));
    }

    #[Test]
    public function emitting_a_streamed_response_writes_the_file_and_closes_the_handle(): void
    {
        $response = $this->get($this->router(), '/style.css');
        $stream = $response->stream;

        ob_start();
        $response->emit();
        $this->assertSame('body{}', ob_get_clean());
        $this->assertFalse(is_resource($stream));
    }

    #[Test]
    public function head_closes_the_opened_file(): void
    {
        $router = $this->router();
        $stream = $this->get($router, '/style.css')->stream;
        $this->assertIsResource($stream);
        fclose($stream);

        $this->assertNull($router->handle('HEAD', '/style.css')->stream);
    }

    /**
     * @return iterable<int, array{string, string}>
     */
    public static function mimeTypes(): iterable
    {
        yield ['a.html', 'text/html; charset=utf-8'];
        yield ['a.css', 'text/css; charset=utf-8'];
        yield ['a.js', 'text/javascript; charset=utf-8'];
        yield ['a.mjs', 'text/javascript; charset=utf-8'];
        yield ['a.json', 'application/json'];
        yield ['a.xml', 'application/xml'];
        yield ['a.txt', 'text/plain; charset=utf-8'];
        yield ['a.svg', 'image/svg+xml'];
        yield ['a.png', 'image/png'];
        yield ['a.jpg', 'image/jpeg'];
        yield ['a.jpeg', 'image/jpeg'];
        yield ['a.gif', 'image/gif'];
        yield ['a.webp', 'image/webp'];
        yield ['a.avif', 'image/avif'];
        yield ['a.ico', 'image/x-icon'];
        yield ['a.woff2', 'font/woff2'];
        yield ['a.woff', 'font/woff'];
        yield ['a.pdf', 'application/pdf'];
        yield ['a.mp4', 'video/mp4'];
        yield ['a.webm', 'video/webm'];
        yield ['a.PNG', 'image/png'];
        yield ['a.unknown', 'application/octet-stream'];
        yield ['noextension', 'application/octet-stream'];
    }

    #[Test]
    #[DataProvider('mimeTypes')]
    public function mime_types_follow_the_extension(string $name, string $type): void
    {
        $response = $this->get($this->router([$name => 'x']), '/'.$name);

        $this->assertSame(200, $response->status);
        $this->assertSame($type, $response->header('Content-Type'));
    }

    #[Test]
    public function a_missing_page_is_404_with_the_artifact_404_page(): void
    {
        $response = $this->get($this->router(), '/nope/');

        $this->assertSame(404, $response->status);
        $this->assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        $this->assertStringContainsString('<h1>Missing</h1>', $response->body);
        $this->assertStringContainsString('var g="'.self::GENERATION.'"', $response->body);
    }

    #[Test]
    public function a_missing_404_page_falls_back_to_plain_text(): void
    {
        $this->router();
        unlink($this->preview.'/404.html');
        $router = new PreviewRouter($this->preview, $this->site.'/.verse/preview/status.json', 0);
        $response = $this->get($router, '/nope');

        $this->assertSame(404, $response->status);
        $this->assertSame('text/plain; charset=utf-8', $response->header('Content-Type'));
        $this->assertSame('Not Found', $response->body);
    }

    #[Test]
    public function a_directory_without_a_trailing_slash_redirects(): void
    {
        $response = $this->get($this->router(), '/dir');

        $this->assertSame(301, $response->status);
        $this->assertSame('/dir/', $response->header('Location'));
        $this->assertSame('', $response->body);
        $this->assertStringContainsString('Dir', $this->get($this->router(), '/dir/')->body);
    }

    #[Test]
    public function a_redirect_location_is_encoded_and_never_leaves_the_server(): void
    {
        $router = $this->router(['über uns/index.html' => 'x']);
        $response = $this->get($router, '/%C3%BCber%20uns');

        $this->assertSame(301, $response->status);
        $this->assertSame('/%C3%BCber%20uns/', $response->header('Location'));
    }

    #[Test]
    public function the_query_string_and_fragment_are_ignored(): void
    {
        $router = $this->router();

        $this->assertSame(200, $this->get($router, '/style.css?v=3&x=../../etc/passwd')->status);
        $this->assertSame(200, $this->get($router, '/?utm=1')->status);
        $this->assertSame(200, $this->get($router, '/dir/?x#top')->status);
        $this->assertSame(200, $this->get($router, '/?/../x')->status);
    }

    #[Test]
    public function only_get_and_head_are_allowed(): void
    {
        $router = $this->router();

        foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'] as $method) {
            $response = $router->handle($method, '/');
            $this->assertSame(405, $response->status, $method);
            $this->assertSame('GET, HEAD', $response->header('Allow'));
        }
        $this->assertSame(405, $router->handle('POST', '/__verse/status')->status);
    }

    #[Test]
    public function head_sends_headers_only(): void
    {
        $router = $this->router();
        $get = $this->get($router, '/');
        $head = $router->handle('HEAD', '/');

        $this->assertSame(200, $head->status);
        $this->assertSame('', $head->body);
        $this->assertNull($head->stream);
        $this->assertSame($get->header('Content-Length'), $head->header('Content-Length'));
        $this->assertSame($get->header('Content-Type'), $head->header('Content-Type'));

        $file = $router->handle('HEAD', '/style.css');
        $this->assertNull($file->stream);
        $this->assertSame('6', $file->header('Content-Length'));
        $this->assertSame('', $router->handle('HEAD', '/nope')->body);
    }

    #[Test]
    public function the_status_endpoint_returns_the_status_file_uncached(): void
    {
        $router = $this->router();
        $response = $router->handle('GET', '/__verse/status?x=1');

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json', $response->header('Content-Type'));
        $this->assertSame('no-store', $response->header('Cache-Control'));
        $this->assertSame(self::GENERATION, json_decode($response->body, true)['generation']);
        $this->assertSame('ok', json_decode($response->body, true)['result']);
    }

    #[Test]
    public function the_status_endpoint_is_pending_without_a_status_file(): void
    {
        $response = $this->get($this->router(status: null), '/__verse/status');

        $this->assertSame(200, $response->status);
        $this->assertSame('{"generation":null,"result":"pending"}', $response->body);
        $this->assertSame('no-store', $response->header('Cache-Control'));
    }

    #[Test]
    public function the_status_endpoint_stays_at_the_server_root_under_a_base_path(): void
    {
        $router = $this->router(status: ['generation' => self::GENERATION, 'basePath' => '/notes/']);

        $this->assertSame(200, $this->get($router, '/__verse/status')->status);
        $this->assertStringContainsString('fetch("/__verse/status"', $this->get($router, '/notes/')->body);
    }

    #[Test]
    public function a_base_path_scopes_the_site(): void
    {
        $router = $this->router(status: ['generation' => self::GENERATION, 'basePath' => '/notes/']);

        $this->assertSame(200, $this->get($router, '/notes/')->status);
        $this->assertStringContainsString('<h1>Home</h1>', $this->get($router, '/notes/')->body);
        $this->assertSame(200, $this->get($router, '/notes/style.css')->status);
        $this->assertSame(301, $this->get($router, '/notes/dir')->status);
        $this->assertSame('/notes/dir/', $this->get($router, '/notes/dir')->header('Location'));

        $root = $this->get($router, '/');
        $this->assertSame(302, $root->status);
        $this->assertSame('/notes/', $root->header('Location'));

        $bare = $this->get($router, '/notes');
        $this->assertSame(301, $bare->status);
        $this->assertSame('/notes/', $bare->header('Location'));

        foreach (['/style.css', '/dir/', '/other/', '/notesx/', '/notes-style.css'] as $outside) {
            $this->assertSame(404, $this->get($router, $outside)->status, $outside);
        }
    }

    #[Test]
    public function an_invalid_base_path_in_the_status_file_falls_back_to_the_root(): void
    {
        $router = $this->router(status: ['generation' => self::GENERATION, 'basePath' => '//evil.example/']);

        $this->assertSame(200, $this->get($router, '/')->status);
    }

    #[Test]
    public function a_symlink_leaving_the_artifact_is_not_found(): void
    {
        $router = $this->router();
        $outside = $this->makeSite(['secret.txt' => 'top secret', 'page/index.html' => 'outside page']);
        symlink($outside.'/secret.txt', $this->preview.'/leak.txt');
        symlink($outside.'/page', $this->preview.'/leakdir');
        symlink($outside, $this->preview.'/sub');

        foreach (['/leak.txt', '/leakdir', '/leakdir/', '/sub/secret.txt', '/sub/page/'] as $uri) {
            $response = $this->get($router, $uri);
            $this->assertSame(404, $response->status, $uri);
            $this->assertNull($response->stream, $uri);
            $this->assertStringNotContainsString('outside page', $response->body, $uri);
        }
    }

    #[Test]
    public function a_symlink_inside_the_artifact_is_followed(): void
    {
        $router = $this->router();
        symlink($this->preview.'/style.css', $this->preview.'/alias.css');

        $this->assertSame(200, $this->get($router, '/alias.css')->status);
    }

    #[Test]
    public function a_symlink_to_a_dot_file_is_not_served(): void
    {
        $router = $this->router();
        symlink($this->preview.'/.verse-output', $this->preview.'/marker.txt');

        $this->assertSame(404, $this->get($router, '/marker.txt')->status);
    }

    #[Test]
    public function a_symlinked_404_page_outside_the_artifact_is_not_used(): void
    {
        $router = $this->router();
        $outside = $this->makeSite(['secret.html' => '<p>top secret</p>']);
        unlink($this->preview.'/404.html');
        symlink($outside.'/secret.html', $this->preview.'/404.html');

        $response = $this->get($router, '/nope');

        $this->assertSame(404, $response->status);
        $this->assertSame('Not Found', $response->body);
    }

    #[Test]
    public function without_a_status_file_every_page_is_the_waiting_diagnostic(): void
    {
        $response = $this->get($this->router(status: null), '/');

        $this->assertSame(503, $response->status);
        $this->assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        $this->assertStringContainsString('Waiting for the first successful build', $response->body);
        $this->assertStringContainsString('var g=null', $response->body);
        $this->assertSame(503, $this->get($this->router(status: null), '/anything')->status);
    }

    #[Test]
    public function without_a_generation_the_diagnostic_shows_the_escaped_error(): void
    {
        $router = $this->router(status: null);
        BuildStatus::failed(null, 'content', 'content error in <b>x</b> & "y"', 'content/<x>.md')->write($this->site.'/.verse/preview/status.json');

        $response = $this->get($router, '/');

        $this->assertSame(503, $response->status);
        $this->assertStringContainsString('The build failed', $response->body);
        $this->assertStringContainsString('content error in &lt;b&gt;x&lt;/b&gt; &amp; &quot;y&quot;', $response->body);
        $this->assertStringContainsString('content/&lt;x&gt;.md', $response->body);
        $this->assertStringNotContainsString('<b>x</b>', $response->body);
        $this->assertStringContainsString('var g=null', $response->body);
    }

    #[Test]
    public function without_an_artifact_directory_the_diagnostic_is_served(): void
    {
        $router = $this->router();
        self::removeTree($this->preview);

        $response = $this->get($router, '/');

        $this->assertSame(503, $response->status);
        $this->assertStringContainsString('var g=null', $response->body);
    }

    #[Test]
    public function an_artifact_without_a_marker_is_not_served(): void
    {
        $response = $this->get($this->router(marker: false), '/');

        $this->assertSame(503, $response->status);
    }

    #[Test]
    public function a_failed_rebuild_keeps_serving_the_previous_artifact(): void
    {
        $router = $this->router();
        BuildStatus::failed(BuildStatus::read($this->site.'/.verse/preview/status.json'), 'content', 'broken', 'content/x.md')
            ->write($this->site.'/.verse/preview/status.json');

        $response = $this->get($router, '/');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('<h1>Home</h1>', $response->body);
    }

    #[Test]
    public function the_script_escapes_the_generation(): void
    {
        $router = $this->router();
        file_put_contents($this->preview.'/.verse-output', '{"id":"</script><script>alert(1)</script>"}');

        $body = $this->get($router, '/')->body;

        $this->assertStringNotContainsString('<script>alert(1)', $body);
        $this->assertStringContainsString('\u003C/script\u003E', $body);
    }
}
