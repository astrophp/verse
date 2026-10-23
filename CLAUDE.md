# CLAUDE.md

Guidance for working in this repository.

## What this is

Verse (`astrophp/verse`, namespace `Astro\Verse\`) is an open-source static site generator
written in PHP. A website is a folder of Markdown files and one `verse.yaml`; Verse renders it
through a bundled Blade theme into static HTML, CSS and JavaScript. It is a command-line tool, not
a Laravel application.

The public roadmap is `ROADMAP.md`. Read it before starting any work.

## Rules that must not be broken

- **Websites never need Node.js.** Vite runs only in this repository to build `themes/*/dist`,
  which is committed.
- **Content never executes.** No PHP or Blade from Markdown; raw HTML is escaped. Hooks in a website's
  `theme/hooks/` are Blade written by the site owner, like a theme; content (Markdown, front matter)
  never reaches Blade.
- **Output is never left half-written.** Builds are staged and promoted only on success; only
  marker-carrying folders are replaced.
- **Errors name the file and the fix.** No stack traces for website problems.
- **This repository is public.** Never commit secrets, credentials, real personal data, private
  paths or anything machine-specific.

## Targets

- PHP `^8.4` (platform pinned to 8.4.1 in `composer.json`), tested on 8.4 and 8.5.
- Symfony Console, league/commonmark, standalone `illuminate/view`, tempest/highlight.
- The `default` theme: plain CSS and JavaScript built by Vite into `themes/default/dist`.

## Workflow

- Branching: `main` only, short-lived feature branches, releases are tags (`v0.1.0`, …).
- The version comes from the tag through Composer (`Verse::version()`); there is no version
  constant to bump. A user-visible change adds its line under `## Unreleased` in `CHANGELOG.md`;
  the release workflow dates that heading. See `docs/releasing.md`.
- Each change is independently mergeable and green in CI.
- Commit and push only when asked.

## Commands

```bash
composer check       # PHPUnit, PHPStan (level 8), Pint --test
vendor/bin/pint      # fix the code style
npm run build        # themes/default/src -> themes/default/dist (commit the result)
```
