# Releasing Verse

A release is a tag on `main`. Verse has no version number in its source: `verse --version` prints
the version Composer installed, which Packagist takes from the tag (`v0.2.0` prints `0.2.0`; a
checkout of `main` prints `dev-main` and the commit). The release notes are the `## Unreleased`
section of `CHANGELOG.md`, so every pull request with a user-visible change adds its line there.

1. Merge the last pull request of the release. `CHANGELOG.md` must have a `## Unreleased` heading
   with the notes for the release under it; the release refuses an empty section.
2. Wait until the `tests` and `install` workflow runs for the head of `main` are green.
3. Run `scripts/release/preflight.sh vX.Y.Z`, then run the `git tag … && git push …` command it
   prints, which tags the commit it checked.
4. Watch the `release` workflow (`gh run watch`, or the Actions tab). It creates the GitHub release
   from the `## Unreleased` notes, then commits `CHANGELOG.md` to `main` with that heading turned
   into `## vX.Y.Z - YYYY-MM-DD` (the release day in UTC) under a new, empty `## Unreleased`.
5. Check that [the package page](https://packagist.org/packages/astrophp/verse) lists the
   version.

Tags are `vMAJOR.MINOR.PATCH`, optionally with `-alpha`, `-beta` or `-rc` followed by a required
number (`v0.2.0-rc.1`, `v0.2.0-beta2`; a bare `-rc` is refused); a suffixed tag becomes a GitHub
pre-release. A pre-release leaves `CHANGELOG.md` alone: its notes stay under `## Unreleased` until
the stable release dates them. The preflight fetches `origin/main` but creates no tag and pushes nothing; it waits for
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

- **verify** failed (invalid tag, commit not on `main`, `tests` or `install` not green for that
  commit, committed theme build differing from a fresh build, missing or empty `## Unreleased`
  section): no GitHub release exists. Fix it on `main` and release
  the next patch version.
- **release** failed: read the message. A release or draft that already exists for the tag is
  never changed; resolve that by hand. If nothing was created, re-run the failed job.
- **changelog** failed: the release exists, but `CHANGELOG.md` on `main` was not dated. When the
  message says `main` changed after the tag, `## Unreleased` may already hold later notes: in a
  pull request, move the released notes under a `## vX.Y.Z - YYYY-MM-DD` heading. Otherwise re-run
  the failed job; it does nothing when `main` already has the heading.
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
