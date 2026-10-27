# Changelog

All notable changes to Verse are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Verse follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Until v1.0.0, a minor version may change
commands, configuration keys, theme views and output.

## Unreleased

### Added

- An Atom feed at `feed.xml` with the 20 newest articles (title, link, dates, description and tags), on
  by default; `feed: false` in `verse.yaml` turns it off. Pages link to it with
  `<link rel="alternate">`. Preview builds do not write it.

## v0.4.0 - 2026-10-23

### Added

- Hooks: a website can add its own Blade snippets to the default theme's pages with
  `theme/hooks/<hook>.blade.php` (`head`, `body-start`, `main-start`, `main-end`, `body-end`,
  `article-before`, `article-after`, `page-before`, `page-after`). Hooks see the page's data and
  `verse()`, which has a new `isPreview()`; an error in a hook fails the build and names the file.
  Themes place them with `@verseHook('head')`.

### Changed

- A website's `theme/` folder is read instead of being ignored with a warning. Only `theme/hooks/`
  is supported in it; anything else there is an error. `themes/` is still ignored with a warning.
- Theme views call the `verse()` helper (`verse()->url('/articles/')`) instead of the `$__verse`
  variable, which is gone.

## v0.3.2 - 2026-10-22

### Changed

- Builds are faster on large websites: the default theme's inlined theme script is read once per
  build instead of once for every page.

## v0.3.1 - 2026-10-22

### Changed

- Builds of websites with many articles are faster: the "Keep reading" link to the next older article
  is found in one pass instead of re-sorting every article for each article.
- Builds are faster: the Markdown parser is set up once per build instead of once for every
  Markdown file.
- Builds are faster: the default theme renders its pages without Blade components, so each page
  needs far fewer template renders. The generated HTML is unchanged.

### Fixed

- URLs in `verse.yaml` and front matter lose surrounding whitespace, such as the trailing newline a
  YAML block scalar (`canonical: |`) keeps, instead of carrying it into `<link rel="canonical">`,
  the sitemap or a link.

## v0.3.0 - 2026-10-21

### Added

- `verse build` writes `sitemap.xml` with the published pages, articles, archive and tag pages. Set
  `sitemap: false` in `verse.yaml` to leave it out.

### Changed

- A `public/sitemap.xml` now conflicts with the generated sitemap; set `sitemap: false` to keep your
  own.
- `verse --version` prints the version Composer installed. A checkout of `main` prints the branch
  and the commit, for example `dev-main (f8d2498)`, instead of the version of the next release.

## v0.2.0 - 2026-10-21

### Added

- `verse check` runs the production build in temporary storage and reports errors and warnings
  without writing `dist/` or `.verse/`.

## v0.1.0 - 2026-10-21

The first release.

### Added

- Installation with Composer from Packagist: `composer global require astrophp/verse`, or
  `composer require --dev astrophp/verse` to pin a version in a website.
- `verse init` creates a website in a new or empty folder: `verse.yaml`, an About page, a first
  article, a draft Markdown showcase article and a `.gitignore`.
- `verse build` renders the website into `dist/` through a staging folder that replaces the previous
  output only when every step succeeds, and refuses to replace a folder it did not create.
- `verse serve` previews the website on `127.0.0.1` with rebuilds on change, live reload, drafts and
  scheduled articles shown with a badge, and build errors shown on open pages.
- Articles in `content/articles/`, pages that follow the folder structure, page bundles with
  attachments, tags and a tag index, drafts, scheduled articles and external articles.
- Markdown with tables, footnotes, callouts, captioned images, server-side code highlighting and
  links between Markdown files resolved to their final URLs. Raw HTML is escaped and content is never
  executed.
- The bundled `default` theme: light and dark mode, a table of contents, a month-grouped archive,
  and ⌘K / Ctrl K search over a static index. Its built assets are committed, so websites never need
  Node.js.
