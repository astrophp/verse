<?php

declare(strict_types=1);

namespace Astro\Verse\Routing;

use Astro\Verse\Error\ContentException;

/**
 * Every output path is claimed here with a description of its owner.
 * A conflicting claim fails immediately and names both owners.
 */
final class OutputRegistry
{
    public const string THEME_PREFIX = 'assets/theme/';

    public const string PREVIEW_PREFIX = '__verse/';

    /** @var array<string, array{path: string, owner: string}> files by lowercase path */
    private array $files = [];

    /** @var array<string, array{path: string, owner: string}> directories by lowercase path */
    private array $directories = [];

    /**
     * @param  string  $path  decoded NFC path inside the output directory, `/`-separated, no leading slash
     * @param  string  $owner  human description, e.g. `page content/about.md`
     */
    public function claim(string $path, string $owner, ClaimSource $source = ClaimSource::Content): void
    {
        $this->assertNotReserved($path, $owner, $source);

        $key = mb_strtolower($path);
        if (isset($this->files[$key])) {
            $existing = $this->files[$key];
            if ($existing['path'] === $path) {
                throw $this->conflict(
                    '"'.$path.'" is produced by both '.$existing['owner'].' and '.$owner.'. '
                    .'Rename one source, or give one of them a different slug or permalink.',
                );
            }

            throw $this->caseConflict($existing['path'], $existing['owner'], $path, $owner);
        }

        if (isset($this->directories[$key])) {
            $directory = $this->directories[$key];
            if ($directory['path'] !== $path) {
                throw $this->caseConflict($directory['path'], $directory['owner'], $path, $owner);
            }

            throw $this->conflict(
                '"'.$path.'" ('.$owner.') is a file, but '.$directory['owner'].' needs "'.$path.'" to be a directory. '
                .'Rename one of them.',
            );
        }

        $segments = explode('/', $path);
        array_pop($segments);
        $prefix = '';
        foreach ($segments as $segment) {
            $prefix = $prefix === '' ? $segment : $prefix.'/'.$segment;
            $prefixKey = mb_strtolower($prefix);

            if (isset($this->files[$prefixKey])) {
                $file = $this->files[$prefixKey];
                if ($file['path'] !== $prefix) {
                    throw $this->caseConflict($file['path'], $file['owner'], $prefix, $owner);
                }

                throw $this->conflict(
                    '"'.$prefix.'" ('.$file['owner'].') is a file, but '.$owner.' needs "'.$prefix.'" to be a directory. '
                    .'Rename one of them.',
                );
            }
            if (isset($this->directories[$prefixKey]) && $this->directories[$prefixKey]['path'] !== $prefix) {
                $directory = $this->directories[$prefixKey];

                throw $this->caseConflict($directory['path'], $directory['owner'], $prefix, $owner);
            }

            $this->directories[$prefixKey] ??= ['path' => $prefix, 'owner' => $owner];
        }

        $this->files[$key] = ['path' => $path, 'owner' => $owner];
    }

    /**
     * @return array<string, string> owner description by output path, sorted by path
     */
    public function owners(): array
    {
        $owners = [];
        foreach ($this->files as $entry) {
            $owners[$entry['path']] = $entry['owner'];
        }
        ksort($owners, SORT_STRING);

        return $owners;
    }

    public function owner(string $path): ?string
    {
        return $this->files[mb_strtolower($path)]['owner'] ?? null;
    }

    private function assertNotReserved(string $path, string $owner, ClaimSource $source): void
    {
        $lower = mb_strtolower($path);

        if (str_starts_with($lower, self::PREVIEW_PREFIX)) {
            throw $this->conflict(
                '"'.$path.'" ('.$owner.') is inside "'.self::PREVIEW_PREFIX.'", which is reserved for Verse preview internals. '
                .'Choose another name or route.',
            );
        }

        if ($source !== ClaimSource::Theme && str_starts_with($lower, self::THEME_PREFIX)) {
            throw $this->conflict(
                '"'.$path.'" ('.$owner.') is inside "'.self::THEME_PREFIX.'", which is reserved for the Verse theme assets. '
                .'Choose another name or route.',
            );
        }
    }

    private function caseConflict(string $firstPath, string $firstOwner, string $secondPath, string $secondOwner): ContentException
    {
        return $this->conflict(
            '"'.$firstPath.'" ('.$firstOwner.') and "'.$secondPath.'" ('.$secondOwner.') differ only by letter case, '
            .'which collides on case-insensitive file systems and hosts. Rename one of them.',
        );
    }

    private function conflict(string $detail): ContentException
    {
        return new ContentException('output conflict: '.$detail);
    }
}
