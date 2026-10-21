# Changelog

All notable changes to Verse are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Verse follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Until v1.0.0, a minor version may change
commands, configuration keys, theme views and output.

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
