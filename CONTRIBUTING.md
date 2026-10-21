# Contributing

Thank you for helping with Verse. This page covers how to set the repository up, which commands to
run before you open a pull request, and the rules a change has to keep.

Please read the [roadmap](ROADMAP.md) first: it says what Verse is for and what it does not do.
Report security problems privately, as [SECURITY.md](SECURITY.md) describes, and not in an issue.

## Setup

You need PHP 8.4.1 or newer with the `intl` and `mbstring` extensions, and Composer. To work on the
bundled theme you also need the Node version in `.nvmrc` (24) with npm.

```bash
composer install
npm install          # only for theme work
```

## Commands

```bash
composer test        # PHPUnit
composer analyse     # PHPStan (level 8)
composer lint        # Pint (--test)
composer check       # all three
vendor/bin/pint      # fix the code style
npm run build        # themes/default/src -> themes/default/dist (commit the result)
npm run watch        # rebuild the theme on change
```

Run `composer check` for every change. Continuous integration runs PHPUnit on PHP 8.4 and 8.5,
PHPStan and Pint, rebuilds the theme, and installs the package archive into a fresh folder to check
that `verse init` and `verse build` work from what a release ships.

- Each pull request should be independently mergeable and green.
- Add tests for a change in behaviour. Unit tests are in `tests/Unit`; tests that build whole
  websites are in `tests/Integration`, with fixture websites in `tests/Fixtures/sites`.
- A test must not depend on the wall clock or the machine: pass a fixed "now" (the helpers in
  `tests/Support/TestCase.php` do) and work in temporary folders.

## The built theme is committed

The default theme's assets are built by Vite from `themes/default/src` into `themes/default/dist`,
and Verse reads them from there. People who install Verse do not run a build, so `dist/` is
committed. Rebuild with `npm run build` whenever `themes/default/src` changes, and commit the result
in the same pull request. CI rebuilds it and fails if the committed files differ.

A `verse serve` running from the same checkout picks up view and asset changes, so you can preview
theme work on any website.

## Rules for changes

- **Websites never need Node.js.** Node is used only to build the bundled themes in this
  repository. A website is Markdown, `verse.yaml` and optional `public/` files.
- **Content never executes.** Markdown is never run as PHP or Blade, raw HTML in Markdown is escaped,
  and unsafe links are dropped.
- **Output is never left half-written.** A build writes to a staging folder and replaces `dist/` only
  when every step succeeds. Verse replaces only folders that carry its marker.
- **Errors name the file and say what to do.** A website problem is reported with the file, the
  field and a suggested fix, never as a stack trace.
- **Output is static.** No feature may require PHP, Node.js or a server process on the host that
  serves the website.
- **The repository is public.** Do not commit secrets, credentials, real personal data or anything
  specific to your machine. Fixture content uses placeholder names and `example.test` domains.

## Pull requests

Branch from `main` with a short, descriptive name. Describe the change on its own terms in the
commit messages and the pull request, and say what you ran. If you change the README or the docs,
check that the links still resolve; `composer test` checks them.
