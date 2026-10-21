#!/usr/bin/env bash
# Prints the release notes under "## vX.Y.Z" or "## vX.Y.Z - YYYY-MM-DD" in a changelog. A CRLF
# changelog works: a trailing carriage return is dropped from every line read.
set -euo pipefail

tag=${1:-}
file=${2:-CHANGELOG.md}

[ -f "$file" ] || { printf 'CHANGELOG.md is missing; %s needs release notes.\n' "$tag" >&2; exit 1; }

awk -v tag="$tag" '
    { sub(/\r$/, "") }
    index($0, "## " tag) == 1 && (substr($0, length("## " tag) + 1) == "" || substr($0, length("## " tag) + 1) ~ /^ - [0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]$/) { found = 1; next }
    found && /^## / { exit }
    found { notes = notes $0 "\n"; if ($0 ~ /[^[:space:]]/) nonempty = 1 }
    END {
        if (!found) { printf "CHANGELOG.md has no heading for %s (expected \"## %s\" or \"## %s - YYYY-MM-DD\").\n", tag, tag, tag > "/dev/stderr"; exit 1 }
        if (!nonempty) { printf "CHANGELOG.md entry for %s has no release notes.\n", tag > "/dev/stderr"; exit 1 }
        sub(/^\n+/, "", notes)
        sub(/\n+$/, "", notes)
        print notes
    }
' "$file"
