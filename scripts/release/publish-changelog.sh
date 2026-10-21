#!/usr/bin/env bash
# Usage: publish-changelog.sh vX.Y.Z [file]
#
# After a release, commits CHANGELOG.md to main with its "## Unreleased" heading turned into
# "## vX.Y.Z - YYYY-MM-DD" (today in UTC) and a new empty "## Unreleased" above it. The file given
# (default CHANGELOG.md) is the changelog of the tagged commit, which the release notes came from.
# It goes through the GitHub contents API, which refuses the update when main's CHANGELOG.md is no
# longer that file, so notes merged after the tag are never labelled with this release. A
# pre-release leaves the changelog alone: its notes stay under "## Unreleased" for the release.
#
# Needs GH_REPO (and GH_TOKEN in CI). RELEASE_GH_COMMAND replaces gh, RELEASE_DATE the date (tests).
set -euo pipefail

tag=${1:-}
file=${2:-CHANGELOG.md}
gh_command=${RELEASE_GH_COMMAND:-gh}
date=${RELEASE_DATE:-$(date -u +%Y-%m-%d)}
dir=$(dirname "$0")

meta=$("$dir/tag-version.sh" "$tag")
case "$meta" in
    *prerelease=true*)
        printf '%s is a pre-release; CHANGELOG.md keeps its notes under "## Unreleased".\n' "$tag"
        exit 0
        ;;
esac

[ -f "$file" ] || { printf '%s is missing.\n' "$file" >&2; exit 1; }
[ -n "${GH_REPO:-}" ] || { printf 'GH_REPO is required (owner/name).\n' >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

tagged=$(git hash-object "$file")
current=$("$gh_command" api "repos/$GH_REPO/contents/CHANGELOG.md?ref=main" --jq .sha)

if [ "$current" != "$tagged" ]; then
    "$gh_command" api "repos/$GH_REPO/contents/CHANGELOG.md?ref=main" -H 'Accept: application/vnd.github.raw' > "$work/main"
    if grep -qE "^## $(printf '%s' "$tag" | sed 's/\./\\./g')( - [0-9]{4}-[0-9]{2}-[0-9]{2})?"$'\r'"?$" "$work/main"; then
        printf 'CHANGELOG.md on main already has a heading for %s.\n' "$tag"
        exit 0
    fi
    printf 'CHANGELOG.md on main changed after %s was tagged, so its "## Unreleased" section may hold later changes. In a pull request, move the notes released in %s under a "## %s - %s" heading.\n' "$tag" "$tag" "$tag" "$date" >&2
    exit 1
fi

cp "$file" "$work/CHANGELOG.md"
"$dir/changelog-release.sh" "$tag" "$date" "$work/CHANGELOG.md"
base64 < "$work/CHANGELOG.md" | tr -d '\n' > "$work/content"

"$gh_command" api --method PUT "repos/$GH_REPO/contents/CHANGELOG.md" \
    -f message="Add $tag to CHANGELOG.md" \
    -f branch=main \
    -f sha="$tagged" \
    -F content=@"$work/content" > /dev/null
printf 'CHANGELOG.md on main now has "## %s - %s".\n' "$tag" "$date"
