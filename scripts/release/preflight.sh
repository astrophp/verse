#!/usr/bin/env bash
# Usage: scripts/release/preflight.sh vX.Y.Z
#
# Checks that a release can be tagged and prints the commands that tag the commit it checked. It
# fetches origin/main (updating that remote-tracking ref), so it is read-only for the repository's
# history and the remote: it creates no tag and pushes nothing. It reads CHANGELOG.md and
# Verse::VERSION as committed on origin/main, not from your working tree, and does not wait for
# workflow runs: a run still in progress is reported and the script exits non-zero
# (PREFLIGHT_WAIT_SECONDS=600 waits up to 600 s).
#
# Settings for tests: PREFLIGHT_REMOTE, PREFLIGHT_BRANCH, PREFLIGHT_COMMIT (default remote/branch),
# PREFLIGHT_SKIP_RUNS=1 (skip the workflow-run check, loudly).
set -euo pipefail

tag=${1:-}
remote=${PREFLIGHT_REMOTE:-origin}
branch=${PREFLIGHT_BRANCH:-main}
commit=${PREFLIGHT_COMMIT:-$remote/$branch}
dir=$(dirname "$0")

"$dir/tag-version.sh" "$tag" > /dev/null

if git rev-parse -q --verify "refs/tags/$tag" > /dev/null; then
    printf 'Release tag %s already exists locally.\n' "$tag" >&2
    exit 1
fi

git fetch --quiet "$remote" "$branch"
if git ls-remote --exit-code --tags "$remote" "refs/tags/$tag" > /dev/null 2>&1; then
    printf 'Release tag %s already exists on %s.\n' "$tag" "$remote" >&2
    exit 1
fi

sha=$(git rev-parse --verify "$commit^{commit}")
"$dir/on-main.sh" "$sha" "${PREFLIGHT_MAIN_REF:-$remote/$branch}"

changelog=$(mktemp)
trap 'rm -f "$changelog"' EXIT
git show "$sha:CHANGELOG.md" > "$changelog" 2> /dev/null || {
    printf 'CHANGELOG.md does not exist at commit %s; %s needs release notes.\n' "$sha" "$tag" >&2
    exit 1
}
"$dir/changelog-entry.sh" "$tag" "$changelog" > /dev/null

version_file=$(mktemp)
trap 'rm -f "$changelog" "$version_file"' EXIT
git show "$sha:src/Verse.php" > "$version_file" 2> /dev/null || {
    printf 'src/Verse.php does not exist at commit %s.\n' "$sha" >&2
    exit 1
}
"$dir/version-matches.sh" "$tag" "$version_file" > /dev/null

if [ "${PREFLIGHT_SKIP_RUNS:-0}" = 1 ]; then
    printf 'Skipping workflow-run checks because PREFLIGHT_SKIP_RUNS=1.\n' >&2
elif ! command -v gh > /dev/null 2>&1; then
    printf 'gh is required to verify tests.yml and install.yml; install it or set PREFLIGHT_SKIP_RUNS=1.\n' >&2
    exit 1
else
    export GH_REPO=${GH_REPO:-$(gh repo view --json nameWithOwner --jq .nameWithOwner)}
    export RELEASE_RUNS_TIMEOUT_SECONDS=${PREFLIGHT_WAIT_SECONDS:-0}
    export RELEASE_RUNS_GRACE_SECONDS=${PREFLIGHT_WAIT_SECONDS:-0}
    "$dir/wait-for-checks.sh" "$sha" "$branch"
fi

printf 'Checked commit %s (%s).\n' "$sha" "$remote/$branch"
if [ "${PREFLIGHT_SKIP_RUNS:-0}" = 1 ]; then
    printf 'Nothing has checked that the committed theme build matches a fresh build (workflow runs were skipped). Check it on that commit: npm ci && npm run build && scripts/release/dist-clean.sh\n'
else
    printf 'The committed theme build is covered by the green tests run for this commit: its Theme assets job fails when a fresh build differs from themes/*/dist. The release workflow checks it again.\n'
fi
printf 'To release, run:\n  git tag %s %s && git push %s %s\n' "$tag" "$sha" "$remote" "$tag"
