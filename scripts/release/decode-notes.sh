#!/usr/bin/env bash
# Writes the base64 release notes in $NOTES to the file named by the first argument.
set -euo pipefail

target=${1:?target file}
printf '%s' "${NOTES:-}" | base64 -d > "$target"
[ -s "$target" ] || { printf 'Release notes are empty.\n' >&2; exit 1; }
