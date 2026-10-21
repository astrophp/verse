#!/usr/bin/env bash
# Reads the tag name of every release, drafts included, one per line on stdin. Exit 0 and a message
# when a release for the tag exists (the release is then never touched); exit 1 when none does.
# releases/tags/{tag} does not show a draft; the releases listing does for a token with push access.
set -euo pipefail

tag=${1:-}
[ -n "$tag" ] || { printf 'A tag is required.\n' >&2; exit 2; }

if grep -qFx -- "$tag" ; then
    printf 'A GitHub release (possibly a draft) already exists for %s; refusing to change it.\n' "$tag" >&2
    exit 0
fi
exit 1
