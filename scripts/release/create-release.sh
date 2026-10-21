#!/usr/bin/env bash
# Creates the GitHub release for a tag from a notes file; a tag with a suffix is a pre-release.
# RELEASE_GH_COMMAND replaces gh (tests).
set -euo pipefail

tag=${1:-}
notes=${2:-}
gh_command=${RELEASE_GH_COMMAND:-gh}

meta=$("$(dirname "$0")/tag-version.sh" "$tag")
[ -f "$notes" ] || { printf 'Release notes file %s is missing.\n' "$notes" >&2; exit 1; }

args=(release create "$tag" --verify-tag --title "$tag" --notes-file "$notes")
case "$meta" in *prerelease=true*) args+=(--prerelease);; esac

"$gh_command" "${args[@]}"
