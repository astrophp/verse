<?php

declare(strict_types=1);

namespace Astro\Verse\Support;

/**
 * Collects non-fatal findings of one build; printed as `warning: …`.
 */
final class Warnings
{
    /** @var list<string> */
    private array $messages = [];

    public function add(string $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * @param  list<string>  $messages
     */
    public function addAll(array $messages): void
    {
        foreach ($messages as $message) {
            $this->add($message);
        }
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->messages;
    }

    public function isEmpty(): bool
    {
        return $this->messages === [];
    }
}
