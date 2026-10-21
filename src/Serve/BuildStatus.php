<?php

declare(strict_types=1);

namespace Astro\Verse\Serve;

use Astro\Verse\Error\FilesystemException;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/**
 * The preview status file `.verse/preview/status.json`: the generation (build id) and base path of the last
 * successful build plus the outcome of the latest one. Written by the build worker (and by `verse serve` when
 * the worker crashes), read by the router and by the live-reload script.
 */
final readonly class BuildStatus
{
    public const string FILE = 'status.json';

    /**
     * @param  ?string  $generation  `.verse-output` id of the last successful build, null before the first one
     * @param  string  $basePath  base path of that build
     * @param  'ok'|'error'  $result
     * @param  ?array{kind: string, message: string, file: ?string}  $error
     */
    public function __construct(
        public ?string $generation,
        public string $basePath,
        public string $result,
        public ?array $error,
        public string $time,
    ) {}

    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function ok(string $generation, string $basePath): self
    {
        return new self($generation, $basePath, 'ok', null, self::now());
    }

    /**
     * A failed build: the previous generation and base path stay.
     */
    public static function failed(?self $previous, string $kind, string $message, ?string $file): self
    {
        return new self(
            $previous?->generation,
            $previous->basePath ?? '/',
            'error',
            ['kind' => $kind, 'message' => $message, 'file' => $file],
            self::now(),
        );
    }

    public static function read(string $path): ?self
    {
        $text = @file_get_contents($path);
        if ($text === false) {
            return null;
        }

        return self::parse($text);
    }

    public static function parse(string $json): ?self
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($data)) {
            return null;
        }

        $generation = $data['generation'] ?? null;
        $basePath = $data['basePath'] ?? '/';
        $result = $data['result'] ?? null;
        $time = $data['time'] ?? '';
        if (($generation !== null && ! is_string($generation)) || ! is_string($basePath) || ! is_string($time) || ($result !== 'ok' && $result !== 'error')) {
            return null;
        }
        if (preg_match('~^/([^/\\\\?#%]+/)*$~', $basePath) !== 1) {
            $basePath = '/';
        }

        $error = null;
        $raw = $data['error'] ?? null;
        if (is_array($raw)) {
            $kind = $raw['kind'] ?? 'unknown';
            $message = $raw['message'] ?? '';
            $file = $raw['file'] ?? null;
            $error = [
                'kind' => is_string($kind) ? $kind : 'unknown',
                'message' => is_string($message) ? $message : '',
                'file' => is_string($file) ? $file : null,
            ];
        }

        return new self($generation, $basePath, $result, $error, $time);
    }

    /**
     * Atomically replaces the file (temporary file in the same directory, then rename).
     */
    public function write(string $path): void
    {
        $contents = json_encode(
            [
                'generation' => $this->generation,
                'basePath' => $this->basePath,
                'result' => $this->result,
                'error' => $this->error,
                'time' => $this->time,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";

        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new FilesystemException('cannot create '.$directory.'.');
        }

        $temporary = $path.'.'.getmypid().'.tmp';
        if (@file_put_contents($temporary, $contents) === false || ! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new FilesystemException('cannot write the preview status file '.$path.'.');
        }
    }
}
