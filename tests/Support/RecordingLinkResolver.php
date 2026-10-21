<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Support;

use Astro\Verse\Markdown\LinkResolver;
use Closure;

/**
 * Link resolver double: records every call and optionally rewrites the URL.
 */
final class RecordingLinkResolver implements LinkResolver
{
    /** @var list<array{string, string, bool}> */
    public array $calls = [];

    /**
     * @param  ?Closure(string): string  $rewrite
     */
    public function __construct(private readonly ?Closure $rewrite = null) {}

    public function resolve(string $sourcePath, string $url, bool $isImage): string
    {
        $this->calls[] = [$sourcePath, $url, $isImage];

        return $this->rewrite === null ? $url : ($this->rewrite)($url);
    }
}
