#!/usr/bin/env bash
# Validates a release tag and prints version=/prerelease= lines.
# Accepted: vMAJOR.MINOR.PATCH, optionally followed by -alpha, -beta, -rc or -RC and a required
# number (-rc1, -rc.1). Those are the pre-release stabilities Composer's version parser gives
# alpha, beta and RC. Composer reads "dev" as a development version and "patch"/"pl"/"p" as
# stable, so neither is a pre-release suffix and both are refused.
set -euo pipefail

tag=${1:-}
pattern='^v[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc|RC)\.?[0-9]+)?$'

if [[ ! $tag =~ $pattern ]]; then
    printf 'Invalid release tag: %s. Expected vMAJOR.MINOR.PATCH with an optional -alpha.N, -beta.N or -rc.N suffix (for example -rc.1).\n' "$tag" >&2
    exit 1
fi

printf 'version=%s\n' "$tag"
if [[ $tag == *-* ]]; then
    printf 'prerelease=true\n'
else
    printf 'prerelease=false\n'
fi
