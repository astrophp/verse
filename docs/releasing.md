# Releasing Verse

1. Merge a pull request that prepares the version:
   - `Astro\Verse\Verse::VERSION` in `src/Verse.php` is the version without the `v` (`0.1.0` for
     `v0.1.0`). `verse --version` prints it, and the release refuses a tag that does not match.
   - `CHANGELOG.md` has an entry for the version: a heading `## vX.Y.Z`, optionally followed by
     ` - YYYY-MM-DD` (a date in exactly that shape; other text after the version is refused), then
     the release notes. The heading must match the tag exactly and the entry must not be empty.
2. Wait until the `tests` and `install` workflow runs for the head of `main` are green.
3. Run `scripts/release/preflight.sh vX.Y.Z`, then run the `git tag … && git push …` command it
   prints, which tags the commit it checked.
4. Watch the `release` workflow (`gh run watch`, or the Actions tab).
5. Check that [the package page](https://packagist.org/packages/astrophp/verse) lists the
   version.
6. Set `Verse::VERSION` to the next development version (`0.2.0-dev`) and open an `## Unreleased`
   section in `CHANGELOG.md`.

Tags are `vMAJOR.MINOR.PATCH`, optionally with `-alpha`, `-beta` or `-rc` followed by a required
number (`v0.2.0-rc.1`, `v0.2.0-beta2`; a bare `-rc` is refused); a suffixed tag becomes a GitHub
pre-release. The preflight fetches `origin/main` but creates no tag and pushes nothing; it waits for
no workflow run (a run still in progress is reported and it exits non-zero; set
`PREFLIGHT_WAIT_SECONDS` to wait). It does not build: the committed `themes/*/dist` is covered by the
green `tests` run it checks, whose Theme assets job fails when a fresh build differs. With
`PREFLIGHT_SKIP_RUNS=1` nothing has checked the build; run
`npm ci && npm run build && scripts/release/dist-clean.sh` on that commit first.

`scripts/install-test.sh` is what the `install` workflow runs. It installs the archive of a commit
(what Packagist ships, with the `export-ignore` paths of `.gitattributes` left out) and builds a new
website from it. Run it locally after you commit; it works in a temporary folder.

## When a job fails

Pushing the tag made it public. Never move or delete a pushed tag; fix the cause and release a new
patch version. Packagist fetches versions from the repository's tags, so it may list a tag even
when no GitHub release exists ([Packagist about](https://packagist.org/about)).

- **verify** failed (invalid tag, commit not on `main`, `Verse::VERSION` not matching the tag,
  `tests` or `install` not green for that commit, committed theme build differing from a fresh
  build, missing or empty changelog entry): no GitHub release exists. Fix it on `main` and release
  the next patch version.
- **release** failed: read the message. A release or draft that already exists for the tag is
  never changed; resolve that by hand. If nothing was created, re-run the failed job.
- **packagist** failed: the GitHub release exists but Packagist does not list the version after
  20 minutes. Use the package page's manual update (see below); once it lists the version, nothing
  else is needed.

## One-time Packagist setup (repository owner)

Follow [Packagist about](https://packagist.org/about).

1. Log in to Packagist and submit `https://github.com/astrophp/verse` with the submit button. Do
   this before the first tag is pushed: until the package exists, its metadata endpoint
   (`https://repo.packagist.org/p2/astrophp/verse.json`) returns 404 and the `packagist` job
   fails.
2. Packagist describes the GitHub hook in the "How to update packages?" section of that page:
   log in through GitHub so Packagist sets the hook up, or add the webhook by hand.
3. Check the package list for a warning that a package is not automatically synced; the page
   suggests a manual account sync to retry the hook.
4. When a tag does not appear, trigger a manual update on the package page.
