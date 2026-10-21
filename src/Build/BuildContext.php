<?php

declare(strict_types=1);

namespace Astro\Verse\Build;

use Astro\Verse\Config\SiteConfig;
use Astro\Verse\Verse;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BuildContext
{
    /**
     * @param  DateTimeImmutable  $now  captured once per build; every eligibility decision uses it
     * @param  string  $basePath  from the configured `url`, `/` when absent
     */
    public function __construct(
        public BuildMode $mode,
        public bool $drafts,
        public SiteConfig $config,
        public string $version,
        public DateTimeImmutable $now,
        public string $basePath,
        public string $root,
        public string $outputDir,
    ) {}

    public function withOutputDir(string $outputDir): self
    {
        return new self($this->mode, $this->drafts, $this->config, $this->version, $this->now, $this->basePath, $this->root, $outputDir);
    }

    /**
     * Whether this build writes `sitemap.xml`: production builds unless `sitemap: false`.
     */
    public function publishesSitemap(): bool
    {
        return $this->mode === BuildMode::Production && $this->config->sitemap;
    }

    public function themeName(): string
    {
        return $this->config->theme->name;
    }

    public static function create(
        string $root,
        SiteConfig $config,
        BuildMode $mode = BuildMode::Production,
        bool $drafts = false,
        ?DateTimeImmutable $now = null,
        ?string $outputDir = null,
    ): self {
        if ($drafts && $mode === BuildMode::Production) {
            throw new InvalidArgumentException('Drafts can only be included in preview builds.');
        }

        $root = rtrim($root, '/');

        return new self(
            mode: $mode,
            drafts: $drafts,
            config: $config,
            version: Verse::version(),
            now: $now ?? new DateTimeImmutable('now'),
            basePath: $config->basePath(),
            root: $root,
            outputDir: $outputDir ?? $root.'/dist',
        );
    }
}
