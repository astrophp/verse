#!/usr/bin/env bash
# Usage: changelog-release.sh vX.Y.Z YYYY-MM-DD [file]
#
# Turns the "## Unreleased" heading of a changelog (default CHANGELOG.md) into "## vX.Y.Z -
# YYYY-MM-DD" and opens a new, empty "## Unreleased" section above it. The file is rewritten in
# place; a CRLF changelog keeps its line endings.
set -euo pipefail

tag=${1:-}
date=${2:-}
file=${3:-CHANGELOG.md}

"$(dirname "$0")/tag-version.sh" "$tag" > /dev/null
[[ $date =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || { printf 'Invalid release date: %s. Expected YYYY-MM-DD.\n' "$date" >&2; exit 1; }
[ -f "$file" ] || { printf '%s is missing.\n' "$file" >&2; exit 1; }

out=$(mktemp)
trap 'rm -f "$out"' EXIT
awk -v heading="## $tag - $date" '
    !done && ($0 == "## Unreleased" || $0 == "## Unreleased\r") {
        eol = substr($0, length("## Unreleased") + 1)
        print "## Unreleased" eol
        print eol
        print heading eol
        done = 1
        next
    }
    { print }
    END { if (!done) { print "CHANGELOG.md has no \"## Unreleased\" heading to release." > "/dev/stderr"; exit 1 } }
' "$file" > "$out"
cat "$out" > "$file"
