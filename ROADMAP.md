# Roadmap

This page lists what the first release contains and what is planned after it. What comes after is
not a commitment to dates. For the detail of a release, see the [changelog](CHANGELOG.md).

Verse is a static site generator for one kind of website: a personal site with articles and a few
pages, written in Markdown and published as plain files. It is a command-line tool, not a Laravel
application, a CMS or a hosted service.

## v0.1.0 — first release

**Commands**

- `verse init` to start a website, `verse serve` to preview it with live reload, `verse build` to
  write the production site.
- Safe output: builds are staged and swapped into place only when they succeed, and Verse never
  replaces a folder it did not create.
- Installation with Composer from Packagist (`composer global require astrophp/verse`).

**Content**

- Articles, pages, page bundles with attachments, tags, drafts, scheduled and external articles.
- Markdown with tables, footnotes, callouts, captioned images and server-side code highlighting.
- Routes that follow the folder structure, with `slug` and `permalink` overrides and links between
  Markdown files resolved at build time.

**Theme**

- One complete bundled theme, `default`, configured from `verse.yaml` (logo, accent colour, table of
  contents), with light and dark mode and static search.

## v0.2.0

- `verse check`, which validates a website with the production pipeline without publishing it.

## v0.3.0

- `sitemap.xml`, on by default (`sitemap: false` turns it off).

## Next

- Hooks: a website adds its own markup to every page from `theme/hooks/`.
- An RSS feed and `robots.txt`.
- Broken-link and heading-anchor validation of the built output.
- Structured data in page metadata.

## Later

These are ideas, not promises.

- A PHAR, for installing without Composer.
- Overriding single theme views (`theme/views/`) and adding a site stylesheet from the website folder.
- More bundled themes.
- Redirects, image processing and multilingual content.

Not planned: a CMS or admin UI, a database, remote content sources, executable PHP or Blade inside
Markdown, a plugin SDK or a theme marketplace.

Ideas and feedback are welcome in [issues](https://github.com/astrophp/verse/issues).
