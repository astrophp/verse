#!/usr/bin/env bash
set -euo pipefail

sha=${1:-}
remote=${2:-origin/main}

if ! git rev-parse --verify --quiet "$remote^{commit}" > /dev/null; then
    printf 'Cannot find %s; fetch it before checking the tagged commit.\n' "$remote" >&2
    exit 1
fi

if ! git merge-base --is-ancestor "$sha" "$remote"; then
    printf 'Tagged commit %s is not an ancestor of %s. Releases must be tagged from main.\n' "$sha" "$remote" >&2
    exit 1
fi

printf 'Tagged commit %s is on main.\n' "$sha"
