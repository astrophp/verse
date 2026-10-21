<?php

declare(strict_types=1);

namespace Astro\Verse\Serve;

/**
 * Decides when `verse serve` starts a rebuild. Time is passed in (seconds, any monotonic clock), so the logic
 * is testable without sleeping.
 *
 * Changes are debounced; at most one build runs at a time; changes that arrive while a build runs set a
 * single dirty flag, which schedules exactly one follow-up build after it.
 */
final class RebuildScheduler
{
    private bool $dirty = false;

    private bool $building = false;

    private float $lastChange = 0.0;

    public function __construct(private readonly float $debounceSeconds = 0.15) {}

    /**
     * Files changed at `$now`.
     */
    public function changed(float $now): void
    {
        $this->dirty = true;
        $this->lastChange = $now;
    }

    /**
     * Wants a build as soon as none is running (the first build), without waiting for the debounce.
     */
    public function requestNow(float $now): void
    {
        $this->dirty = true;
        $this->lastChange = $now - $this->debounceSeconds;
    }

    /**
     * Whether a build starts now. When true, the caller starts the worker and calls `finished()` when it ends.
     */
    public function tick(float $now): bool
    {
        if ($this->building || ! $this->dirty || $now - $this->lastChange < $this->debounceSeconds) {
            return false;
        }

        $this->dirty = false;
        $this->building = true;

        return true;
    }

    public function finished(): void
    {
        $this->building = false;
    }

    public function isBuilding(): bool
    {
        return $this->building;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }
}
