#!/usr/bin/env bash
# Run after a fresh build: fails when the build changed or added any committed asset.
set -euo pipefail

paths=${*:-themes}
# shellcheck disable=SC2086
changes=$(git status --porcelain -- $paths)

if [ -n "$changes" ]; then
    printf 'A fresh build differs from what is committed (M modified, ?? untracked):\n%s\nRun npm run build and commit the result.\n' "$changes" >&2
    exit 1
fi

printf 'The committed build matches a fresh build.\n'
