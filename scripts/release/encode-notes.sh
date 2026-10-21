#!/usr/bin/env bash
# Reads release notes on stdin and prints a notes=<base64> line for $GITHUB_OUTPUT.
set -euo pipefail

encoded=$(base64 | tr -d '\n')
if [ "${#encoded}" -gt 900000 ]; then
    printf 'Release notes are too large for a job output.\n' >&2
    exit 1
fi
printf 'notes=%s\n' "$encoded"
