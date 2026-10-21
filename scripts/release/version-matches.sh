#!/usr/bin/env bash
# Fails unless Astro\Verse\Verse::VERSION in a source file (default src/Verse.php) is the tag
# without its leading "v". `verse --version` prints that constant, so a release must carry it.
set -euo pipefail

tag=${1:-}
file=${2:-src/Verse.php}

[ -f "$file" ] || { printf '%s is missing; it holds Verse::VERSION.\n' "$file" >&2; exit 1; }

version=$(sed -n "s/^[[:space:]]*public const string VERSION = '\([^']*\)';.*/\1/p" "$file")
[ -n "$version" ] || { printf 'Cannot find Verse::VERSION in %s.\n' "$file" >&2; exit 1; }

if [ "$version" != "${tag#v}" ]; then
    printf 'Verse::VERSION is %s but the tag is %s. Set it to %s in src/Verse.php and merge that first.\n' "$version" "$tag" "${tag#v}" >&2
    exit 1
fi

printf 'Verse::VERSION is %s.\n' "$version"
