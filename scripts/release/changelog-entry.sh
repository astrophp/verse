#!/usr/bin/env bash
# Prints the release notes under "## Unreleased" in a changelog: the notes of the tag being
# released (changelog-release.sh turns that heading into "## vX.Y.Z - YYYY-MM-DD" afterwards). A
# CRLF changelog works: a trailing carriage return is dropped from every line read.
set -euo pipefail

tag=${1:-}
file=${2:-CHANGELOG.md}

[ -f "$file" ] || { printf 'CHANGELOG.md is missing; %s needs release notes under "## Unreleased".\n' "$tag" >&2; exit 1; }

awk -v tag="$tag" '
    { sub(/\r$/, "") }
    !found && $0 == "## Unreleased" { found = 1; next }
    found && /^## / { exit }
    found { notes = notes $0 "\n"; if ($0 ~ /[^[:space:]]/) nonempty = 1 }
    END {
        if (!found) { printf "CHANGELOG.md has no \"## Unreleased\" heading; add one above the latest release with the notes for %s.\n", tag > "/dev/stderr"; exit 1 }
        if (!nonempty) { printf "CHANGELOG.md has no release notes under \"## Unreleased\"; add the notes for %s there.\n", tag > "/dev/stderr"; exit 1 }
        sub(/^\n+/, "", notes)
        sub(/\n+$/, "", notes)
        print notes
    }
' "$file"
