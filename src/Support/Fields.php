<?php

declare(strict_types=1);

namespace Astro\Verse\Support;

use Astro\Verse\Error\VerseException;
use Closure;

/**
 * Typed access to one YAML mapping with field-specific errors (key paths like `theme.accent`).
 */
final class Fields
{
    /**
     * @param  array<array-key, mixed>  $data
     * @param  Closure(string, ?string): VerseException  $error  builds the exception for (detail, field)
     */
    public function __construct(
        private readonly array $data,
        private readonly Closure $error,
        private readonly string $path = '',
    ) {}

    /**
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function fieldPath(string $key): string
    {
        return $this->path === '' ? $key : $this->path.'.'.$key;
    }

    public function fail(string $detail, ?string $key = null): never
    {
        throw ($this->error)($detail, $key === null ? ($this->path === '' ? null : $this->path) : $this->fieldPath($key));
    }

    /**
     * @param  list<string>  $allowed
     * @param  string  $hint  appended to every message, e.g. ` (put custom values under "extra")`
     */
    public function rejectUnknown(array $allowed, string $hint = ''): void
    {
        foreach (array_keys($this->data) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                $this->fail('unknown key "'.$this->fieldPath((string) $key).'"'.$hint.'.', (string) $key);
            }
        }
    }

    public function string(string $key): ?string
    {
        if (! $this->has($key)) {
            return null;
        }

        $value = $this->data[$key];
        if (! is_string($value)) {
            $this->fail('expected a string, got '.self::describe($value).'.', $key);
        }

        return $value;
    }

    public function nonEmptyString(string $key): ?string
    {
        $value = $this->string($key);
        if ($value !== null && trim($value) === '') {
            $this->fail('must not be empty.', $key);
        }

        return $value;
    }

    /**
     * A non-empty string without its surrounding whitespace, for values such as URLs where it is never meant:
     * a YAML block scalar (`url: |`) keeps a trailing newline, and a quoted value can keep stray spaces.
     */
    public function trimmedString(string $key): ?string
    {
        $value = $this->nonEmptyString($key);

        return $value === null ? null : trim($value);
    }

    public function requiredString(string $key): string
    {
        if (! $this->has($key)) {
            $this->fail('is required.', $key);
        }

        return $this->nonEmptyString($key) ?? $this->fail('is required.', $key);
    }

    public function bool(string $key, bool $default): bool
    {
        if (! $this->has($key)) {
            return $default;
        }

        $value = $this->data[$key];
        if (! is_bool($value)) {
            $this->fail('expected true or false, got '.self::describe($value).'.', $key);
        }

        return $value;
    }

    public function int(string $key, ?int $min = null): ?int
    {
        if (! $this->has($key)) {
            return null;
        }

        $value = $this->data[$key];
        if (! is_int($value)) {
            $this->fail('expected an integer, got '.self::describe($value).'.', $key);
        }
        if ($min !== null && $value < $min) {
            $this->fail('must be at least '.$min.', got '.$value.'.', $key);
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function map(string $key): ?array
    {
        if (! $this->has($key)) {
            return null;
        }

        $value = $this->data[$key];
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->fail('expected a mapping, got '.self::describe($value).'.', $key);
        }

        return $value;
    }

    /**
     * A nested mapping as its own Fields (error paths keep the prefix).
     */
    public function nested(string $key): ?self
    {
        $map = $this->map($key);

        return $map === null ? null : new self($map, $this->error, $this->fieldPath($key));
    }

    /**
     * @return list<mixed>|null
     */
    public function list(string $key): ?array
    {
        if (! $this->has($key)) {
            return null;
        }

        $value = $this->data[$key];
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail('expected a list, got '.self::describe($value).'.', $key);
        }

        return $value;
    }

    public function raw(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean '.($value ? 'true' : 'false'),
            is_int($value) => 'integer '.$value,
            is_float($value) => 'number '.$value,
            is_string($value) => 'string "'.(mb_strlen($value) > 40 ? mb_substr($value, 0, 40).'…' : $value).'"',
            is_array($value) => array_is_list($value) ? 'a list' : 'a mapping',
            default => get_debug_type($value),
        };
    }
}
