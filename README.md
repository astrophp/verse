<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/astrophp/verse/main/art/logo-dark.svg">
    <img src="https://raw.githubusercontent.com/astrophp/verse/main/art/logo-light.svg" alt="Verse logo" width="240">
  </picture>
</p>

<p align="center">
  <strong>Write in Markdown. Design with Blade. Publish anywhere.</strong><br>
  A static site generator written in PHP.
</p>

<p align="center">
  <a href="https://github.com/astrophp/verse/actions/workflows/tests.yml"><img src="https://github.com/astrophp/verse/actions/workflows/tests.yml/badge.svg" alt="tests"></a>
  <a href="composer.json"><img src="https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.4 or later"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-green" alt="MIT licence"></a>
</p>

<p align="center">
  <a href="#installation">Install</a> ·
  <a href="#quick-start">Quick start</a> ·
  <a href="#configuration-verseyaml">Configuration</a> ·
  <a href="ROADMAP.md">Roadmap</a>
</p>

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/astrophp/verse/main/art/home-dark.png">
    <img src="https://raw.githubusercontent.com/astrophp/verse/main/art/home-light.png" alt="The home page of a Verse website in the default theme: the site description, then the latest articles in a timeline grouped by month, each with its date, description and tags." width="900">
  </picture>
</p>

Verse is a command-line static site generator written in PHP. A website is an ordinary folder of
Markdown files with YAML front matter and one `verse.yaml`; Verse renders it through a bundled Blade
theme into plain static HTML, CSS and JavaScript that any static host can serve.

> [!WARNING]
> Verse is early software. Until v1.0.0, a minor version may change commands, configuration keys,
> theme views and output; the [changelog](CHANGELOG.md) lists every change.

- Four commands: `verse init`, `verse serve`, `verse check`, `verse build`.
- Articles, pages, page bundles with attachments, tags, drafts and scheduled posts, external articles.
- One complete bundled theme (`default`) with light/dark mode, a table of contents, code highlighting
  and ⌘K / Ctrl K search over a static index.
- Websites contain no theme files and never need Node.js.

## Requirements

- PHP 8.4.1 or later (CLI) with the `intl` and `mbstring` extensions
- Composer
- Optional: `pcntl`, for clean Ctrl-C handling in `verse serve`

Node.js is needed only to work on the bundled theme inside this repository, never to build a website.

## Installation

Install Verse globally with Composer from
[Packagist](https://packagist.org/packages/astrophp/verse):

```bash
composer global require astrophp/verse
verse --version
```

Composer puts `verse` in its global bin directory. If your shell cannot find the command, add that
directory to your `PATH`; `composer global config bin-dir --absolute` prints it. Update with
`composer global update astrophp/verse`.

A website can also pin its own Verse version, for example to build it in CI. Run this in the website
folder (after `verse init`, which only works in a new or empty folder) and add `/vendor/` to its
`.gitignore`:

```bash
composer require --dev astrophp/verse
vendor/bin/verse build
```

To run unreleased code from `main`, clone this repository, run `composer install` and use
`bin/verse`. Its `--version` prints the branch and the commit of the last `composer install`, for
example `Verse dev-main (f8d2498)`.

## Quick start

```bash
verse init my-site
cd my-site
verse serve            # preview at http://127.0.0.1:8000 with live reload
verse check            # run the production build in temporary storage, without writing dist/
verse build            # write the production site to dist/
```

`verse init` creates a `verse.yaml`, an About page, a first article and a `.gitignore` in a new or
empty folder. It also adds `content/articles/markdown-showcase/`, a draft that shows every Markdown
feature the theme renders; `verse serve` shows it, `verse build` never publishes it. Until you
replace the placeholder values (`https://example.com/`, `Your Name`, `your-username`), `verse build`
warns about them.

`verse check` runs everything `verse build` runs (configuration, theme, content, routes, rendering,
assets and output validation) in a private temporary folder that it deletes afterwards. It never
writes `dist/` or `.verse/`, so it is safe to run at any time, also while `verse serve` or
`verse build` is running. It validates the files as it reads them; it does not take a snapshot of a
site you are editing at the same time. Errors and warnings are reported exactly as `verse build`
reports them.

## Website layout

```text
my-site/
├── verse.yaml
├── content/
│   ├── about.md                     # → /about/
│   ├── articles/                    # the articles folder (fixed)
│   │   ├── an-article.md            # → /articles/an-article/
│   │   └── another-article/
│   │       ├── page.md              # page bundle → /articles/another-article/
│   │       └── diagram.png          # attachment → /articles/another-article/diagram.png
│   └── legal/
│       └── privacy.md               # plain folder → /legal/privacy/
├── public/                          # copied to the output root unchanged (optional)
├── .verse/                          # private generated state (ignored)
└── dist/                            # production output (ignored)
```

- Every `.md` file in `content/articles/` (or bundle folder in it) is an **article**; every other
  Markdown file under `content/` is a **page**.
- A folder containing `page.md` is a **page bundle**: every other file in it is an attachment, served
  relative to the page's URL.
- `page.md` cannot sit directly in `content/` or `content/articles/`, subfolders of
  `content/articles/` must be bundles, and `index.md` / `_index.md` are reserved. Verse fails with an
  error naming the file and suggesting a fix.
- Hidden files are ignored; symbolic links under `content/` or `public/` are rejected.

## Front matter

```markdown
---
title: Hello, Verse
description: A first article to show how a Verse site is written.
date: 2026-10-20
tags: [Verse]
---
Markdown starts here.
```

| Field | Meaning |
| --- | --- |
| `title` | Required. Also the menu label of a page. |
| `description` | Summary for listings and meta tags. |
| `date` | Required for articles. `YYYY-MM-DD`, `YYYY-MM-DDTHH:MM[:SS]`, optionally with `Z` or `±HH:MM`. |
| `updated` | Date of the last substantive update. |
| `draft` | `true` keeps the item out of production builds. |
| `tags` | List of tag labels. |
| `slug` | Replaces the last segment of the URL. |
| `permalink` | Full site-relative URL, such as `/essays/trace/`. |
| `canonical` | Canonical URL when the content is published elsewhere first. |
| `image` | Social / featured image. |
| `external_url` | Articles only: list the article but link to another site instead of rendering a page. |
| `menu`, `order` | Pages only: show the page in the main menu, ordered by `order`. |
| `extra` | Free-form map for your own values. |

Unknown keys are rejected; put custom values under `extra`. Articles with `draft: true` or a future
`date` are **drafts** and **scheduled** items: `verse build` leaves them out of every page, listing,
menu and the search index. External articles appear in listings, tag pages and search, marked with ↗,
but produce no local page.

Markdown supports headings, lists, links, images with captions, tables, footnotes, fenced code with
server-side highlighting, and callouts (`> [!NOTE]`). Raw HTML in Markdown is escaped, and content is
never executed as PHP or Blade. Relative links to other Markdown files and attachments are resolved to
their final URLs.

## Configuration: `verse.yaml`

```yaml
title: My Site
url: https://example.com/          # required by `verse build`; may include a base path: https://example.com/notes/
description: A short description of my site.
tagline: Your role or a short tagline
author: Your Name                  # or {name, url, email}
social_links:
  github: https://github.com/your-username
language: en
timezone: Europe/Berlin
sitemap: true                      # the default; false leaves sitemap.xml out of `verse build`
theme:
  name: default
  logo: null                       # path or URL
  accent: '#2f6feb'                # or {light: '#…', dark: '#…'}
  toc: true
extra:
  anything: you want
```

| Key | Default | Meaning |
| --- | --- | --- |
| `title` | required | Site name, shown in the header. |
| `url` | required for `build` | Production origin plus an optional directory base path. |
| `description` | none | Intro text on the home page and its meta description. |
| `tagline` | none | Short text beside the site name in the header. |
| `author` | none | A name, or `{name, url, email}`. |
| `social_links` | `{}` | Footer links in the order written. GitHub, LinkedIn, Medium, X, Mastodon, Bluesky, YouTube and Email get fixed labels. |
| `language` | `en` | `<html lang>` and date formatting. |
| `timezone` | `UTC` | Used for dates without an offset and for deciding what is scheduled. |
| `sitemap` | `true` | `verse build` writes `sitemap.xml`; `false` leaves it out. |
| `theme` | `{name: default}` | The bundled theme and its settings (`logo`, `accent`, `toc` for `default`). |
| `extra` | `{}` | Your own values, available to templates. |

Unknown keys and invalid values fail with an error naming the field.

`sitemap.xml` lists the home page, pages, articles, archive pages and tag pages as absolute URLs
built from `url`. Drafts, scheduled articles, external articles, pages whose `canonical` is on another
host and `404.html` are left out. A page's `lastmod` is its `date`. A `public/sitemap.xml` is an error
unless you set `sitemap: false`.

## Routes

| URL | Content |
| --- | --- |
| `/` | Site description, the 5 newest articles and an "All articles" link. |
| `/articles/` | Archive of all articles, newest first, grouped by month, 10 per page (`/articles/page/2/`, …). |
| `/articles/<name>/` | An article (or its `slug` / `permalink`). |
| `/tags/` | Every tag with its article count, with a client-side filter. |
| `/tags/<tag>/` | Articles with that tag, paginated like the archive. |
| `/<path>/` | Pages, following the folder structure under `content/`. |
| `/404.html` | Not-found page. |
| `/search.json` | The static search index. |
| `/sitemap.xml` | The sitemap, unless `sitemap: false`. |

The main menu is Home · Articles · Tags, followed by pages with `menu: true` (by `order`, then by title).

## `verse serve`

```bash
verse serve [--port=8000] [--no-drafts]
```

Builds the site into `.verse/preview/`, serves it on `127.0.0.1` and rebuilds when `verse.yaml`,
`content/` or `public/` change; open pages reload automatically. Drafts and scheduled articles are
shown with a badge and a `noindex` instruction. `--no-drafts` previews exactly what `verse build` will
publish, except that the preview does not generate `sitemap.xml`. A failed rebuild prints the error
and shows it as an overlay on open pages, which keep the last good preview.

## `verse build`

Builds the site in the current folder into `dist/`. The build is written to a staging folder under
`.verse/` first and swapped into place only when every step succeeds, so a failing build leaves the
previous `dist/` untouched. Verse marks its output with a `.verse-output` file and refuses to replace a
`dist/` folder that does not carry that marker. Upload the contents of `dist/` to any static host.

## Themes

Themes live in this repository under `themes/<name>/` and are selected with `theme.name`; only
`default` ships today. A theme has a `theme.yaml` (name, description, Vite entries, settings with
defaults), Blade views in `views/`, sources in `src/` and prebuilt assets in `dist/`. Websites cannot
override views or styles yet.

To work on the default theme you need Node.js:

```bash
npm install
npm run build      # rebuild themes/default/dist/
npm run watch      # rebuild on change
```

A `verse serve` running from the same checkout picks up view and asset changes. The built
`themes/default/dist/` is committed, so installing Verse never requires Node.js; commit it together
with the source change.

## Development

```bash
composer install
composer test        # PHPUnit
composer analyse     # PHPStan (level 8)
composer lint        # Pint (--test)
composer check       # all three
```

Not implemented yet: an RSS feed and `robots.txt`.

## Contributing

Issues and pull requests are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) for the setup, the
commands to run and the rules a change has to keep, and the [roadmap](ROADMAP.md) for what Verse is
and is not meant to do. Report security problems privately, as [SECURITY.md](SECURITY.md)
describes. Changes are listed in the [changelog](CHANGELOG.md).

## License

Verse is open-source software licensed under the [MIT license](LICENSE).
